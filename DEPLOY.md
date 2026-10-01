# 本番デプロイ手順

常時起動の Linux サーバー1台に、Docker Compose で本番環境を構築する手順です。
想定するサーバーは Oracle Cloud Always Free の Ampere A1（ARM）ですが、Ubuntu が動く 2GB 以上の VPS であれば同じ手順で動きます。

## 構成

```
                    ┌──────────────── サーバー（Docker Compose） ────────────────┐
インターネット ──▶  │ app        FrankenPHP（Caddy 内蔵）: HTTPS 終端 + API       │
  :80 / :443        │ worker     queue:work（取込ジョブの実行）                   │
                    │ scheduler  schedule:work（毎日の取得・取込・状態確認）      │
                    │ mysql      MySQL 8.4（外部には非公開）                      │
                    └────────────────────────────────────────────────────────────┘
```

scheduler は毎日（日本時間）次の順に実行します。

| 時刻 | コマンド | 内容 |
|---|---|---|
| 05:00 | `rhb:download` | 各局の一覧ページを確認し、新しい版を取得 |
| 05:30 | `rhb:import` | 取込ジョブをキューに投入（worker が実行） |
| 毎月3日 04:40 | `population:import` | 総務省の市区町村別人口（住民基本台帳）を取り込む（年1回の公開時だけ。集計APIの人口あたりの件数に使う） |
| 毎月2日 04:30 | `medical-info-net:import` | 厚生労働省「医療情報ネット」の座標を取り込む（年2回の公開時だけ。取り込むと、町丁目レベルの施設を次の `facilities:geocode` で付け直す） |
| 06:30 | `facilities:geocode` | 新規・移転した施設の座標を住所から求める（アドレス・ベース・レジストリのファイルを `storage-app` ボリュームに保存し、変わったものだけ再取得） |
| 07:00 | `rhb:status` | すべての局・カテゴリが取込済みで最新かを確認 |
| 07:10 | `rhb:export` | 一括ダウンロードのファイルを作成（データが変わったときだけ。全国で約1分半、ファイルは約50MB） |
| 00:15 | `access-log:check` | 前日のアクセスログを集計し、攻撃の疑いがあれば通知（「8. 不審なアクセスの確認と遮断」） |
| 03:00 | `cache:prune-expired` | 期限切れのキャッシュ（集計APIの結果・IPごとのレート制限）をデータベースから削除 |

| ファイル | 内容 |
|---|---|
| [Dockerfile](Dockerfile) | 本番用イメージ（app / worker / scheduler で共通） |
| [compose.production.yaml](compose.production.yaml) | 本番用の Compose 定義（ローカル開発用の `compose.yaml` は Sail） |
| [.env.production.example](.env.production.example) | 本番用 `.env` のひな形 |

- **HTTPS**: `SERVER_NAME` にホスト名を設定すると、Caddy が Let's Encrypt の証明書を自動で取得・更新します。独自ドメインがなくても、[sslip.io](https://sslip.io/)（`203-0-113-1.sslip.io` のように、IP アドレスを含むホスト名がその IP に解決される無料サービス）で HTTPS にできます。
- **ストレージ**: `rhb:download`（scheduler）が保存したファイルを取込ジョブ（worker）が読むため、`storage/app` は両コンテナで共有するボリュームにしています。
- **ログ**: アプリのログとアクセスログを、ボリューム（`storage-logs`）上の日ごとのファイルに14日分残します。エラーは Discord / Slack に通知できます（「7. ログ」）。

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
docker compose run --rm app php artisan db:seed --class=MunicipalitySeeder --force
docker compose up -d
```

- `db:seed` は必ず `--class=` を指定してください（`KanjiVariantSeeder`、`MunicipalitySeeder`）。`DatabaseSeeder` はテストユーザーを作るもので、本番イメージには必要な開発用パッケージ（Faker）が入っていません。

初回のデータ取込を実行します。全国分で20分前後かかります（目安: 約230行/秒）。

```bash
docker compose exec scheduler php artisan rhb:download
docker compose exec app php artisan rhb:import --wait
docker compose exec app php artisan medical-info-net:import   # 医療情報ネットの座標（約20秒）
docker compose exec app php artisan population:import         # 市区町村別の人口（数秒）
docker compose exec app php artisan facilities:geocode   # 座標を付与
docker compose exec app php artisan rhb:export        # 一括ダウンロードのファイルを作成
```

- `facilities:geocode` は、初回にアドレス・ベース・レジストリのファイルを取得します全国で約3.4GB、初回は1時間前後。翌日以降は新規・移転した施設だけを処理します（全国に散らばった200件で約8分。対象の施設がない日はすぐ終わります）。PHP の既定のメモリ上限（128MB）で動きます。ファイルは `storage-app` ボリュームに残り、翌日以降は変わったものだけを取得します。

以降は scheduler が毎日 05:00 / 05:30（日本時間）に自動で実行します。

### 動作確認

```bash
curl https://203-0-113-1.sslip.io/up
curl "https://203-0-113-1.sslip.io/api/v1/medical-facilities?per_page=1"
```

ブラウザで `https://<SERVER_NAME>/docs/api` を開き、仕様書が表示されることも確認します。

証明書が取得できない場合は `docker compose logs app` を確認します。よくある原因は、ポート 80 / 443 が閉じていること（「1. サーバーの準備」のセキュリティ・リストと iptables）です。

### デモ用フロントエンドとの接続

[medical-facility-frontend](https://github.com/TomonoriYoshida/medical-facility-frontend)（GitHub Pages）は、ビルド時に API のオリジンを埋め込みます。未設定のあいだは「API は公開準備中」と表示し、API を呼び出しません。

1. フロントエンドのリポジトリの Settings → Secrets and variables → Actions → Variables で、リポジトリ変数 `API_ORIGIN` に `https://<SERVER_NAME>` を設定します（末尾の `/` と `/api` は付けない）。
2. 変数を変えただけではデプロイされないため、Actions の「Deploy to GitHub Pages」を「Run workflow」で実行します。
3. 公開されたサイトで検索できることを確認します。API は CORS ですべてのオリジンを許可しているため（`config/cors.php`）、API 側の設定は不要です。

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

### 市区町村マスタの投入・更新

市区町村コードの機能を含む版へ初めて更新するとき、および `database/seeders/data/municipalities.csv` を更新した版をデプロイしたときは、`migrate` の後に次を実行します。

```bash
docker compose run --rm app php artisan db:seed --class=MunicipalitySeeder --force
docker compose run --rm app php artisan facilities:assign-municipalities
docker compose run --rm app php artisan rhb:export --force
```

- `facilities:assign-municipalities` は施設の `updated_at` を変えないため、毎日の `rhb:export` は「データが変わっていない」と判断して一括ダウンロードを作り直しません。`--force` で作り直して、ファイルにも市区町村の列を反映させます。

### 市区町村の人口（初回）

人口の機能を含む版へ初めて更新したときは、`migrate` の後に次を実行します（数秒）。以降は scheduler が毎月3日に新しい版を確認します。

```bash
docker compose run --rm app php artisan population:import
```

### 座標の付与（初回）

座標の機能を含む版へ初めて更新したときは、`migrate` の後に次を実行します（所要時間とディスクは「初回のデータ取込」の注記を参照）。座標が変わった施設は `updated_at` が更新されるため、一括ダウンロードは翌朝の `rhb:export` で自動的に作り直されます。すぐに反映する場合は `rhb:export` も実行します。

```bash
docker compose run --rm app php artisan medical-info-net:import
docker compose run --rm app php artisan facilities:geocode
```

- 以降は scheduler が毎日 06:30 に、新規・移転した施設だけを処理します。
- アドレス・ベース・レジストリの位置データは拡充されていくため、ときどき（数か月に1回程度）`facilities:geocode --all` で全施設を付け直すと、町丁目レベルだった施設が番地レベルになることがあります。

## 4. バックアップ

`medical_facility_events`（開業・廃止などの変更履歴）は、取込を重ねて記録していくデータです。各局の公開データから作り直せないので、定期的にバックアップします。

```bash
mkdir -p ~/backups && chmod 700 ~/backups   # ダンプには開設者名・管理者名（個人名）が含まれるため、本人だけが読めるようにする
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
docker compose logs -f worker                  # 取込ジョブのログ（直近。過去の分は「7. ログ」）
docker compose exec app php artisan rhb:status     # 局・カテゴリごとの取込状況
docker compose exec app php artisan queue:failed   # 失敗したジョブ
docker compose exec app php artisan rhb:import --force --wait   # 取込をやり直す
docker compose exec app php artisan rhb:prune --dry-run         # 範囲（RHB_PREFECTURES / RHB_CATEGORIES）を狭めた後、範囲外のデータを確認
docker compose exec app php artisan rhb:prune                   # 確認のうえ削除
```

## 6. 監視

[healthchecks.io](https://healthchecks.io/)（無料プランで20件まで）で、日次処理の失敗と停止を通知します。scheduler が処理のたびに「成功」または「失敗」を送り、失敗が届いたとき、または予定の時刻を過ぎても何も届かないとき（サーバーや scheduler の停止）に、healthchecks.io が通知します。

| チェック | 送信元 | 失敗として通知される例 |
|---|---|---|
| download | `rhb:download`（05:00） | 局のサイトにつながらない、一覧ページの構造が変わりリンクを読み取れない、カテゴリのリンクが見つからない |
| status | `rhb:status`（07:00） | 取込ジョブの失敗（未取込）、局のデータが一度も取得できていない（未取得）、最新の公開日が45日より古い（`RHB_STALE_AFTER_DAYS` で変更可） |

設定手順:

1. healthchecks.io にサインアップします。通知先には登録したメールアドレスが最初から設定されています。Discord や Slack なども「Integrations」で追加できます。
2. チェックを2つ作ります（「Add Check」）。スケジュールは「Cron」を選び、タイムゾーンを `Asia/Tokyo` にします。

   | 名前 | Cron | Grace Time |
   |---|---|---|
   | `rhb-download` | `0 5 * * *` | 1 hour |
   | `rhb-status` | `0 7 * * *` | 1 hour |

3. それぞれの ping URL（`https://hc-ping.com/...`）を `.env` の `RHB_HEALTHCHECK_DOWNLOAD_URL` / `RHB_HEALTHCHECK_STATUS_URL` に設定し、`docker compose up -d` で反映します。
4. 動作を確認します。healthchecks.io の画面で、チェックの状態が「up」になれば届いています。

   ```bash
   docker compose exec scheduler php artisan schedule:test --name=rhb:status
   ```

通知が来たら、`rhb:status` と、アプリのログ（「7. ログ」）で原因を確認します。取得の失敗はどの局で何が起きたか、`rhb:status` の失敗はどの局・カテゴリに問題があるかがログに残ります（scheduler から実行したコマンドの画面出力は残らないため）。取込ジョブの失敗は翌日の `rhb:import` で自動的に再試行されますが、一覧ページの構造の変更はリゾルバ（`app/Services/Rhb/Download/`）の修正が必要です。

## 7. ログ

| ログ | 場所 | 内容 |
|---|---|---|
| アプリのログ | `storage/logs/laravel-YYYY-MM-DD.log` | API のエラー、取込・取得・状態確認の結果（app / worker / scheduler の3つが同じファイルに書く） |
| アクセスログ | `storage/logs/access.log`（1日ごとに `access-<日時>-<理由>.log.gz` へ切り替え、gzip 圧縮） | すべてのリクエスト（JSON。IP アドレス・URL・ステータス・応答時間など） |

- どちらもボリューム `storage-logs` にあり、コンテナを作り直す更新のデプロイでも消えません。14日より古いものは自動で削除します（アプリのログは `LOG_DAILY_DAYS`）。
- アクセスログには利用者の IP アドレスが含まれるため、保存期間を14日にしています。`Cookie` と `Authorization` ヘッダーは Caddy が伏せて記録します。
- `docker compose logs` にも同じアプリのログが出ますが、こちらはコンテナを作り直すと消えます。

```bash
docker compose exec app sh -c 'tail -n 100 storage/logs/laravel-$(date +%F).log'   # 今日のアプリのログ
docker compose exec app sh -c 'grep -h "\.ERROR" storage/logs/laravel-*.log'      # 14日分のエラー
docker compose exec app tail -f storage/logs/access.log                          # アクセスログを流し見る
```

アプリのログの日付は UTC です（`laravel-2026-10-01.log` は日本時間の 10/1 09:00 〜 10/2 09:00）。

### エラーの通知

`LOG_ALERT_WEBHOOK_URL` に Webhook の URL を設定すると、エラー（`ERROR` 以上）を Discord または Slack に送ります。API のリクエスト中のエラーも、毎日の処理の失敗も送ります（404 や 422、429 は送りません）。

- 同じエラーは1時間に1回だけ送ります（`LOG_ALERT_DEDUP_SECONDS`）。同じかどうかは、例外の種類と発生した場所（プロジェクト内のファイルと行）で判断します。データベースの停止などで同じエラーが続いても、通知が大量に届くことはありません。
- 通知は要約です。例外の全文（スタックトレースなど）は、アプリのログで確認します。
- Webhook に送れなかった場合も、アプリの動作やログへの記録には影響しません。

設定手順（Discord の場合）:

1. 通知を受けるチャンネルの「チャンネルの編集」→「連携サービス」→「ウェブフック」で、ウェブフックを作り、URL をコピーします。
2. `.env` の `LOG_ALERT_WEBHOOK_URL` に、その URL の末尾に `/slack` を付けたものを設定します（例: `https://discord.com/api/webhooks/123/abc/slack`。Discord が Slack と同じ形式で受け付けます）。Slack の場合は、Incoming Webhook の URL をそのまま設定します。
3. `docker compose up -d` で反映し、通知が届くことを確認します。

   ```bash
   docker compose exec app php artisan tinker --execute 'Log::error("通知のテスト");'
   ```

## 8. 不審なアクセスの確認と遮断

### 毎日の自動確認

scheduler が毎日 00:15（日本時間）に `access-log:check` を実行し、前日のアクセスログを集計します。次のどれかに当てはまると、エラーとしてログに記録し、Discord / Slack に通知します（「7. ログ」のエラーの通知）。

| 確認すること | 既定のしきい値（1日あたり） | 疑われる攻撃 |
|---|---|---|
| 429（レート制限）の件数 | 50件以上（`ACCESS_ALERT_RATE_LIMITED`） | 大量のリクエスト（スクレイピング、負荷をかける攻撃） |
| 404（見つからない）の件数 | 200件以上（`ACCESS_ALERT_NOT_FOUND`） | 脆弱性スキャン（`/wp-login.php`、`/.env` などを探す） |
| 1つの IP アドレスからのリクエスト数 | 3,000件以上（`ACCESS_ALERT_REQUESTS_PER_IP`） | 1か所からの大量アクセス。全国分をページ送りで取得する正当な利用は約2,250件です |

レート制限（1分60回）が攻撃を防いでいても、この確認がなければ気づけません。通常のアクセスで通知が来る場合は、しきい値を `.env` で調整してください。集計した件数は、通知がない日もアプリのログに `access-log:` で始まる行として残ります。

### 攻撃を疑ったときの調べ方

まず、日ごとの集計を確認します（アクセスログを残している14日前まで）。

```bash
docker compose exec app php artisan access-log:check                     # 前日
docker compose exec app php artisan access-log:check --date=2026-10-01   # 指定した日（日本時間）
```

リクエスト数・429・404・5xx の件数、リクエストの多い IP アドレス、404 の多いパスを表示します。さらに詳しく見るときは、アクセスログを直接調べます。サーバーに `jq` を入れておきます（`sudo apt install jq`）。

```bash
# 14日分のアクセスログ（圧縮されたものを含む）を1つにまとめて取り出す
docker compose exec -T app sh -c 'zcat -f storage/logs/access*.log*' > /tmp/access.jsonl

# 特定の IP アドレスのリクエスト（時刻・メソッド・URL・ステータス）
jq -r 'select(.request.client_ip == "198.51.100.1") | [(.ts | todate), .request.method, .request.uri, .status] | @tsv' /tmp/access.jsonl

# 時間ごとのリクエスト数（いつから増えたか）
jq -r '.ts | strftime("%Y-%m-%d %H:00")' /tmp/access.jsonl | sort | uniq -c

# User-Agent の上位（攻撃ツール名が出ることがある）
jq -r '.request.headers["User-Agent"][0] // "-"' /tmp/access.jsonl | sort | uniq -c | sort -rn | head

rm /tmp/access.jsonl   # IP アドレスを含むので、調べ終わったら消す
```

`jq` の時刻は UTC です。

### IP アドレスの遮断

レート制限を超えたリクエストは 429 を返すだけで、アプリへの負荷は小さく済みます。それでも同じ IP アドレスから続く場合は、ファイアウォールで遮断します。Docker が公開したポートへの通信は、`INPUT` チェーンや ufw を通らないため、`DOCKER-USER` チェーンに追加します。

```bash
sudo iptables -I DOCKER-USER -s 198.51.100.1 -j DROP        # 遮断
sudo iptables -L DOCKER-USER -n --line-numbers              # 確認
sudo iptables -D DOCKER-USER -s 198.51.100.1 -j DROP        # 解除
```

この設定はサーバーを再起動すると消えます。攻撃は一時的なことがほとんどなので、通常はこれで十分です。回線を埋めるほどの大規模な攻撃は、サーバー1台では防げません。独自ドメインを使う場合は、Cloudflare などを前に置くことを検討してください（「補足」のレート制限の注意も参照）。

### SSH

SSH へのログインの試行は、アプリではなく Ubuntu のログに残ります。Oracle の Ubuntu イメージは鍵でしかログインできないため、パスワードの総当たりが成功する心配はありません。

```bash
sudo journalctl -u ssh --since yesterday | grep -c "Invalid user"   # 存在しないユーザーでの試行の件数
```

## 9. 独自ドメインへの切り替え

1. ドメインの DNS に、サーバーの IP アドレスを指す A レコードを追加します（例: `api.example.com`）。
2. `.env` の `SERVER_NAME` と `APP_URL` を新しいホスト名に変えます。
3. `docker compose up -d` を実行します。Caddy が新しいホスト名で証明書を取得します。
4. フロントエンドの `API_ORIGIN` を新しいホスト名に変え、ワークフローを実行し直します（「デモ用フロントエンドとの接続」）。

## 補足

- **ファイアウォール**: Docker が公開したポートは、Ubuntu の ufw の設定を経由せずに外部から到達できます。この構成で公開しているのは 80 / 443 だけ（MySQL は公開していない）ですが、`compose.production.yaml` にポートを追加するときは注意してください。
- **レート制限**: API は IP アドレスごとに1分60回です（`API_RATE_LIMIT_PER_MINUTE`）。仕様書（`/docs/api`）は1分30回（`DOCS_RATE_LIMIT_PER_MINUTE`）、一括ダウンロードのファイルは1時間30回（`EXPORT_DOWNLOADS_PER_HOUR`）です。この構成では app コンテナが直接接続を受けるため、利用者の IP アドレスがそのまま使われます。将来 Cloudflare のプロキシなどを前段に置く場合は、`bootstrap/app.php` で `trustProxies` を設定しないと、全利用者が同じ IP として扱われます。
- **MySQL のメモリ**: `MYSQL_INNODB_BUFFER_POOL_SIZE` は 512MB 以上にしてください。これを下回ると、検索のたびにディスク読み込みが発生し、応答が 1〜2秒に遅くなります。メモリに余裕があるサーバーでは増やしてもかまいません。
