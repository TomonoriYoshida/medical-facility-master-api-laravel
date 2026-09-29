# 本番デプロイ手順

常時起動の Linux サーバー1台に、Docker Compose で本番環境を構築する手順です。
想定するサーバーは Oracle Cloud Always Free の Ampere A1（ARM）ですが、Ubuntu が動く 2GB 以上の VPS であれば同じ手順で動きます。

## 構成

```
                    ┌──────────────── サーバー（Docker Compose） ────────────────┐
インターネット ──▶  │ app        FrankenPHP（Caddy 内蔵）: HTTPS 終端 + API       │
  :80 / :443        │ worker     queue:work（取込ジョブの実行）                   │
                    │ scheduler  schedule:work（05:00 rhb:download / 05:30 rhb:import）│
                    │ mysql      MySQL 8.4（外部には非公開）                      │
                    └────────────────────────────────────────────────────────────┘
```

| ファイル | 内容 |
|---|---|
| [Dockerfile](Dockerfile) | 本番用イメージ（app / worker / scheduler で共通） |
| [compose.production.yaml](compose.production.yaml) | 本番用の Compose 定義（ローカル開発用の `compose.yaml` は Sail） |
| [.env.production.example](.env.production.example) | 本番用 `.env` のひな形 |

- **HTTPS**: `SERVER_NAME` にホスト名を設定すると、Caddy が Let's Encrypt の証明書を自動で取得・更新します。独自ドメインがなくても、[sslip.io](https://sslip.io/)（`203-0-113-1.sslip.io` のように、IP アドレスを含むホスト名がその IP に解決される無料サービス）で HTTPS にできます。
- **ストレージ**: `rhb:download`（scheduler）が保存したファイルを取込ジョブ（worker）が読むため、`storage/app` は両コンテナで共有するボリュームにしています。
- **ログ**: すべて標準エラー出力に出します。`docker compose logs` で確認でき、コンテナごとに 10MB × 5 世代でローテーションします。

## 1. サーバーの準備

### Oracle Cloud の場合

1. 先にネットワークを作ります。ホーム画面の「Set up a network with a wizard」→「Create VCN with Internet Connectivity」を既定値のまま実行すると、パブリック・サブネットとインターネット・ゲートウェイが作られます。
   - インスタンス作成画面の中で VCN を新規作成すると、パブリック IP の割り当てを有効にできない場合があります。
2. インスタンスを作成します。
   - イメージ: Canonical Ubuntu 24.04
   - シェイプ: `VM.Standard.A1.Flex`、1 OCPU / 6GB（Always Free の上限は合計 4 OCPU / 24GB。足りなくなれば後から変更できる）
     - Always Free のインスタンスは、CPU・メモリ等の使用率が低い状態が続くと回収される場合があります。必要以上に大きいシェイプにすると使用率が低く見えるため、小さめにしています。
   - ネットワーク: 手順1の VCN と public サブネットを選び、パブリック IPv4 アドレスの自動割り当てを有効にします。この IP アドレスはインスタンスを停止・再起動しても変わらず、削除したときだけ解放されます。
   - SSH キー: 手元の公開鍵（例: `~/.ssh/id_ed25519.pub`）を貼り付けます。
3. 「Out of capacity for shape VM.Standard.A1.Flex」と表示された場合は、A1 の空きがありません（東京などの人気リージョンでは頻繁に起きます）。次のいずれかで対処します。
   - [OCI CLI](https://docs.oracle.com/iaas/Content/API/Concepts/cliconcepts.htm) の `oci compute instance launch` を、成功するまで数分おきに再試行する。短い間隔で呼び続けると API の利用制限にかかるため、60秒以上あけます。
   - 従量課金（Pay As You Go）にアップグレードする。Always Free の範囲なら請求は発生せず、A1 を確保しやすくなります。無料枠外のリソースを誤って作らないよう、予算アラートを設定しておきます。
   - 国内 VPS の 2GB プランなど、別のサーバーで同じ手順を進める。
4. VCN のセキュリティ・リストに、次のイングレス・ルールを追加します（ソース `0.0.0.0/0`）。
   - TCP 80（HTTP。証明書の取得にも使う）
   - TCP 443（HTTPS）
   - UDP 443（HTTP/3。任意）
5. Oracle の Ubuntu イメージは、SSH 以外の受信を拒否する iptables ルールが初期設定されています。80 / 443 を許可します。

   ```bash
   sudo iptables -I INPUT 6 -m state --state NEW -p tcp --dport 80 -j ACCEPT
   sudo iptables -I INPUT 6 -m state --state NEW -p tcp --dport 443 -j ACCEPT
   sudo iptables -I INPUT 6 -p udp --dport 443 -j ACCEPT
   sudo netfilter-persistent save
   ```

### Docker のインストール

```bash
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker "$USER"
# 一度ログアウトして再ログインすると、sudo なしで docker を実行できる
```

メモリが 2GB の VPS では、初回取込などのピークに備えてスワップを追加しておきます。

```bash
sudo fallocate -l 2G /swapfile && sudo chmod 600 /swapfile
sudo mkswap /swapfile && sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
```

## 2. 初回デプロイ

```bash
git clone https://github.com/TomonoriYoshida/medical-facility-master-api-laravel.git
cd medical-facility-master-api-laravel
cp .env.production.example .env
```

`.env` の `<...>` の部分を埋めます。

| 項目 | 設定する値 |
|---|---|
| `SERVER_NAME` | サーバーの IP アドレスの `.` を `-` に置き換えたもの + `.sslip.io`（例: IP が `203.0.113.1` なら `203-0-113-1.sslip.io`） |
| `APP_URL` | `https://` + `SERVER_NAME` の値 |
| `APP_KEY` | `echo "base64:$(openssl rand -base64 32)"` の出力 |
| `DB_PASSWORD` / `DB_ROOT_PASSWORD` | それぞれ `openssl rand -hex 24` の出力 |

`.env` の `COMPOSE_FILE` により、以降の `docker compose` は本番用の定義を使います。

```bash
docker compose build                    # 初回は数分かかる
docker compose run --rm app php artisan migrate --force
docker compose run --rm app php artisan db:seed --class=KanjiVariantSeeder --force
docker compose up -d
```

- `db:seed` は必ず `--class=KanjiVariantSeeder` を指定してください。`DatabaseSeeder` はテストユーザーを作るもので、本番イメージには必要な開発用パッケージ（Faker）が入っていません。

初回のデータ取込を実行します。全国分で20分前後かかります（目安: 約230行/秒）。

```bash
docker compose exec scheduler php artisan rhb:download
docker compose exec app php artisan rhb:import --wait
```

以降は scheduler が毎日 05:00 / 05:30（日本時間）に自動で実行します。

### 動作確認

```bash
curl https://203-0-113-1.sslip.io/up
curl "https://203-0-113-1.sslip.io/api/v1/medical-facilities?per_page=1"
```

ブラウザで `https://<SERVER_NAME>/docs/api` を開き、仕様書が表示されることも確認します。

証明書が取得できない場合は `docker compose logs app` を確認します。よくある原因は、ポート 80 / 443 が閉じていること（「1. サーバーの準備」のセキュリティ・リストと iptables）です。

## 3. 更新のデプロイ

```bash
cd medical-facility-master-api-laravel
git pull
docker compose build
docker compose run --rm app php artisan migrate --force
docker compose up -d
```

- worker は、実行中のジョブが終わるまで最大10分待ってから停止します（`stop_grace_period`）。取込の時間帯（05:00〜06:00 頃）を避けると、すぐに切り替わります。
- 設定（config）とルートのキャッシュは、コンテナの起動時に作り直されます。`.env` を変えた場合も `docker compose up -d` で反映されます。

## 4. バックアップ

`medical_facility_events`（開業・廃止などの変更履歴）は、取込を重ねて記録していくデータです。各局の公開データから作り直せないので、定期的にバックアップします。

```bash
mkdir -p ~/backups
crontab -e
```

```cron
# 毎日 04:00 にダンプを取り、14日分を残す
0 4 * * * cd ~/medical-facility-master-api-laravel && docker compose exec -T mysql sh -c 'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --no-tablespaces "$MYSQL_DATABASE"' | gzip > ~/backups/mf-$(date +\%F).sql.gz && find ~/backups -name 'mf-*.sql.gz' -mtime +14 -delete
```

サーバーごと失われる場合に備えて、ときどきバックアップを手元や Object Storage にコピーしておくと安全です。

復元:

```bash
gunzip -c ~/backups/mf-2026-10-01.sql.gz | docker compose exec -T mysql sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'
```

## 5. 運用

```bash
docker compose ps                              # 各コンテナの状態
docker compose logs -f worker                  # 取込ジョブのログ
docker compose exec app php artisan queue:failed   # 失敗したジョブ
docker compose exec app php artisan rhb:import --force --wait   # 取込をやり直す
```

## 6. 独自ドメインへの切り替え

1. ドメインの DNS に、サーバーの IP アドレスを指す A レコードを追加します（例: `api.example.com`）。
2. `.env` の `SERVER_NAME` と `APP_URL` を新しいホスト名に変えます。
3. `docker compose up -d` を実行します。Caddy が新しいホスト名で証明書を取得します。

## 補足

- **レート制限**: API は IP アドレスごとに1分60回です（`API_RATE_LIMIT_PER_MINUTE`）。この構成では app コンテナが直接接続を受けるため、利用者の IP アドレスがそのまま使われます。将来 Cloudflare のプロキシなどを前段に置く場合は、`bootstrap/app.php` で `trustProxies` を設定しないと、全利用者が同じ IP として扱われます。
- **MySQL のメモリ**: `MYSQL_INNODB_BUFFER_POOL_SIZE` は 512MB 以上にしてください。これを下回ると、検索のたびにディスク読み込みが発生し、応答が 1〜2秒に遅くなります。メモリに余裕があるサーバーでは増やしてもかまいません。
