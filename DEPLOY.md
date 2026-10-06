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
5. Oracle の Ubuntu イメージは、SSH 以外の受信を拒否する iptables ルールが初期設定されています。80 / 443 を許可するルールを、その拒否のルール（`REJECT`）より**前**に挿入します。後ろに入れると効きません（2026年10月の Ubuntu 24.04 イメージでは、`REJECT` は INPUT の5番目でした）。

   ```bash
   N=$(sudo iptables -L INPUT -n --line-numbers | awk '$2 == "REJECT" { print $1; exit }')
   sudo iptables -I INPUT "$N" -p udp --dport 443 -j ACCEPT
   sudo iptables -I INPUT "$N" -m state --state NEW -p tcp --dport 443 -j ACCEPT
   sudo iptables -I INPUT "$N" -m state --state NEW -p tcp --dport 80 -j ACCEPT
   sudo netfilter-persistent save
   sudo iptables -L INPUT -n --line-numbers   # 80 / 443 の ACCEPT が REJECT より上にあることを確認
   ```

   FORWARD にも同様の `REJECT` がありますが、Docker は起動時に自身のルール（`DOCKER-USER` / `DOCKER-FORWARD`）をその前に追加するため、公開したポートに届きます。`netfilter-persistent save` は Docker のインストール前に実行してください（Docker のルールまで保存されるのを避けるため）。

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

### 診療時間・休診日（初回）

診療時間の機能を含む版へ初めて更新したときは、`migrate` の後に次を実行します（全国で数分）。取り込み済みの版でも、施設IDと診療時間を入れるために `--force` で取り込み直します。以降は scheduler が毎月2日に新しい版を確認します。

```bash
docker compose run --rm app php artisan medical-info-net:import --force
```

- 取り込み直すと、座標が町丁目・医療情報ネット・なしの施設が、次の 06:30 の `facilities:geocode` で付け直す対象になります（`updated_at` は座標が実際に変わった施設だけ動きます）。

### 受付中の施設の検索・祝日（初回）

`open_at` と祝日の機能を含む版へ初めて更新したときは、`migrate` の後に次を実行します（照合は全国で数分）。以降は scheduler が、祝日を毎月4日に、照合を毎朝 06:50 に行います。

```bash
docker compose run --rm app php artisan holidays:import
docker compose run --rm app php artisan facilities:assign-opening-hours
```

- 照合するまで、診療時間のAPIはすべての施設で `data: null` を返し、`open_at` には何も当てはまりません。`migrate` の直後に続けて実行してください。

### 外字の置き換えと照合用キーの変更（初回）

外字の置き換えを含む版へ初めて更新したときは、`migrate` の後に次を実行します。取り込み直しは全国で20分ほどかかるため、朝の定期実行（05:00〜07:15）を避けます。

```bash
docker compose run --rm app php artisan rhb:import --force --wait
docker compose run --rm app php artisan medical-info-net:import --force
docker compose run --rm app php artisan facilities:assign-opening-hours --force
```

- `rhb:import --force` は、外字を含む施設（名前約360件・住所約60件、個人名を含めると約4,500件）を「再処理」の変更として記録し、`updated_at` を動かします。一括ダウンロードは翌朝の `rhb:export` で作り直されます。
- 照合用キーの作り方を変えた版では、`medical-info-net:import --force` で医療情報ネット側のキーを作り直し、`facilities:assign-opening-hours --force` で照合し直します。

## 4. バックアップ

`medical_facility_events`（開業・廃止などの変更履歴）は、取込を重ねて記録していくデータです。各局の公開データから作り直せないので、定期的にバックアップします。

バックアップの処理はスクリプトにまとめ、cron からはそれを呼ぶだけにします（cron の1行に書くと、`%` のエスケープや引用符の入れ子を間違えやすく、そのまま試すこともできないため）。`~/backup-db.sh` を次の内容で作ります。

```bash
#!/usr/bin/env bash
# Daily dump of the production database, 14 days kept. The dump contains
# personal names (founder/administrator), so ~/backups is readable by its
# owner only. pipefail makes a failed mysqldump fail the script instead of
# leaving an empty gzip behind; the .tmp rename keeps a failed run from
# replacing a good file.
set -euo pipefail

cd ~/medical-facility-master-api-laravel
mkdir -p ~/backups
chmod 700 ~/backups

out=~/backups/mf-$(date +%F).sql.gz

docker compose exec -T mysql sh -c 'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --no-tablespaces "$MYSQL_DATABASE" 2>/dev/null' \
    | gzip > "$out.tmp"
mv "$out.tmp" "$out"

find ~/backups -name 'mf-*.sql.gz' -mtime +14 -delete
```

```bash
chmod 700 ~/backup-db.sh
~/backup-db.sh && ls -l ~/backups   # 一度実行して確認（全国分で約9秒、gzip 後で約33MB）
crontab -e
```

サーバーの時計は UTC です（Oracle Cloud の Ubuntu の既定）。日本時間の 04:00（05:00 の取得の前）は UTC の 19:00 なので、cron には次のように書きます。

```cron
# The server clock is UTC: 19:00 UTC = 04:00 JST, before the 05:00 JST download.
0 19 * * * $HOME/backup-db.sh >> $HOME/backup-db.log 2>&1
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

### CPU と送信量

日次処理の見張り（上記）とは別に、CPU の張り付きと、インターネットへの送信量を見張ります。どちらも、クローラーや攻撃に当日中に気づくためのものです。Oracle Cloud は送信量が月10TB を超えると、従量課金（Pay As You Go）では超過分を請求し、上限で止める仕組みはありません。

**CPU（OCI のアラーム）**: CPU 使用率が85%を超えた状態が30分続いたら、メールで通知します。毎月の取込（約20分）では鳴らない条件です。

1. Notifications のトピックを作り、メールの購読を追加します（届いた確認メールのリンクを押すと有効になります）。
2. Monitoring でアラームを作ります。名前空間 `oci_computeagent`、クエリ `CpuUtilization[1m]{resourceId = "<インスタンスの OCID>"}.mean() > 85`、保留期間 30分、通知先は1のトピック。

**送信量（vnstat）**: 外部向けのインターフェース（`enp0s6`）の送信量を `vnstat` で数え、1時間ごとに healthchecks.io に送ります。その日（UTC）の送信量が50GB、または今月の送信量が5TB を超えたら `/fail` を送り、通知されます。OCI のエージェントの指標 `NetworksBytesOut` は、Docker の内部の通信（アプリと MySQL の間など）まで数え、コンテナを作り直すとリセットされるため、使いません。VCN の指標（`oci_vnic`）は、2026年10月時点ではこのテナンシーに記録されていませんでした。

1. `sudo apt-get install -y vnstat`
2. healthchecks.io にチェックを作ります（名前 `egress`、Schedule は Simple、Period 1 hour、Grace Time 1 hour）。
3. `~/check-egress.sh` を次の内容で作り、`chmod 700` します。

```bash
#!/usr/bin/env bash
# Hourly check of the server's internet egress, the traffic Oracle Cloud bills
# beyond 10 TB a month. Counts only the external interface (enp0s6) via
# vnstat: the instance agent's NetworksBytesOut metric also counts Docker's
# internal traffic (app <-> MySQL) and resets when containers are recreated.
#
# Pings a healthchecks.io check (HC_URL in ~/.egress-check.env): "/fail" with
# the figures once today's or this month's egress passes its limit, so an
# attack or a runaway crawler is noticed long before the free 10 TB is gone.
# vnstat's days and months follow the server clock (UTC).
set -euo pipefail

IFACE=enp0s6
DAILY_LIMIT_GB=50
MONTHLY_LIMIT_GB=5000

HC_URL=
# shellcheck source=/dev/null
[[ -f ~/.egress-check.env ]] && source ~/.egress-check.env

read -r today_gb month_gb < <(vnstat --json -i "$IFACE" | python3 -c '
import json, sys
traffic = json.load(sys.stdin)["interfaces"][0]["traffic"]
days, months = traffic["day"], traffic["month"]
today = days[-1]["tx"] if days else 0
month = months[-1]["tx"] if months else 0
print("%.2f %.2f" % (today / 1e9, month / 1e9))
')

message="egress ${IFACE}: today ${today_gb} GB (limit ${DAILY_LIMIT_GB}), this month ${month_gb} GB (limit ${MONTHLY_LIMIT_GB})"
echo "$(date -u '+%F %T') ${message}"

over=$(python3 -c "print(int(${today_gb} > ${DAILY_LIMIT_GB} or ${month_gb} > ${MONTHLY_LIMIT_GB}))")

if [[ -n "$HC_URL" ]]; then
    if [[ "$over" == 1 ]]; then
        curl -fsS -m 10 --retry 3 --data-raw "$message" "${HC_URL}/fail" > /dev/null
    else
        curl -fsS -m 10 --retry 3 --data-raw "$message" "$HC_URL" > /dev/null
    fi
fi
```

4. ping URL を `~/.egress-check.env` に書き（`HC_URL=https://hc-ping.com/...`、`chmod 600`）、cron に追加します。

```cron
5 * * * * $HOME/check-egress.sh >> $HOME/check-egress.log 2>&1
```

### 秘密情報の持ち出し（おとりのキー）

偽の AWS のアクセスキーを、盗まれやすい場所に置きます。偽のキーなので何もできませんが、誰かが使った時点でメールが届きます。攻撃者は盗んだ AWS のキーをすぐに試すことが多いため、`.env` や手元の PC から秘密情報が持ち出されたことに気づけます。キーは [Canarytokens](https://canarytokens.org/)（Thinkst 社の無料サービス）で作ります。

| 置き場所 | 気づける漏洩 |
|---|---|
| サーバーの `.env`（`AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY`） | アプリの脆弱性やサーバーへの侵入による、`.env` やコンテナの環境変数の持ち出し |
| サーバーの `~/.aws/credentials` | サーバーへの侵入 |
| 手元の PC の `~/.aws/credentials` | 手元の PC の乗っ取り（悪意のあるパッケージは `~/.aws` をよく狙います） |

- このアプリは AWS を使っていません（キャッシュとキューはデータベース、ファイルはローカル、メールはログ）。`.env` の `AWS_*` はどこからも使われないため、置いても動作は変わりません。将来 S3 などを使うときは、本物のキーに置き換えてください。
- おとりのキーは git に入れないでください（`.env.example` にも書かない）。CI の gitleaks で失敗します。
- 通知が届いたら、「10. 秘密情報が漏れたときの対応」に進みます。

設定手順:

1. [Canarytokens](https://canarytokens.org/) で「AWS API Key」を選び、通知先のメールアドレスと、置き場所が分かるメモ（例: `medical-facility server .env`）を入れて作ります。メモは Thinkst 社にも見えるため、秘密情報は書きません。どこから漏れたかが分かるよう、置き場所ごとに1つずつ作ります。
2. サーバーの `.env` の末尾に、1つめのキーを追加し、コンテナに反映します。

   ```bash
   # .env に追記する（値は Canarytokens に表示されたもの）
   # AWS_ACCESS_KEY_ID=AKIA...
   # AWS_SECRET_ACCESS_KEY=...
   docker compose up -d
   ```

3. サーバーと手元の PC の `~/.aws/credentials` に、それぞれのキーを置きます。Canarytokens には、このファイルの形式で表示されます。

   ```bash
   mkdir -p ~/.aws && chmod 700 ~/.aws
   install -m 600 /dev/null ~/.aws/credentials   # ファイルがまだないときだけ
   # ~/.aws/credentials に追記する（本物の AWS のキーがあれば、[default] ではなく別のプロファイル名にする）
   # [default]
   # aws_access_key_id = AKIA...
   # aws_secret_access_key = ...
   ```

4. 届くことを確かめる場合は、`aws` コマンドがある環境で、キーを使ってみます。エラーが返るのは正常で、数分〜20分ほどでメールが届きます。

   ```bash
   AWS_ACCESS_KEY_ID=AKIA... AWS_SECRET_ACCESS_KEY=... aws sts get-caller-identity
   ```

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
| 1つの IP アドレスからの脆弱性探し | 10件以上（`ACCESS_ALERT_SCANNER_REQUESTS_PER_IP`） | `.env`・`.git`・`.php` などを探す脆弱性スキャン。下記の自動遮断のあとの 403 も数えます |

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

### 脆弱性探しの自動遮断

`.env`・`.git` などのドットファイル（`.well-known` を除く）、`.php`、`wp-admin` などの WordPress、`cgi-bin`、`phpmyadmin`、`.sql`・`.bak` は、このアプリにはなく、脆弱性スキャンしか求めません（`App\Services\AccessLog\ScannerPaths`）。これらを求めた IP アドレスには、その後24時間、API を含むすべてのリクエストに 403 を返します（`App\Http\Middleware\BlockScanners`）。探したリクエスト自体には、ほかの存在しないパスと同じ 404 を返します。

- 遮断するとアプリのログに `scanner-block:` で始まる警告を1行残します（通知はしません。通知は上の毎日の確認で行います）。
- 遮断の時間は `SCANNER_BLOCK_HOURS`（0で無効）で変えられます。遮断した IP アドレスはファイルのキャッシュに保存するため、コンテナを作り直すデプロイで解除されます。
- 正当な利用者を遮断してしまった場合は、次のように解除します。

```bash
docker compose exec app php artisan tinker --execute 'Cache::store("file")->forget("scanner-block:198.51.100.1");'
```

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
- **レート制限**: API は IP アドレスごとに1分60回です（`API_RATE_LIMIT_PER_MINUTE`）。仕様書（`/docs/api`）は1分30回（`DOCS_RATE_LIMIT_PER_MINUTE`）、一括ダウンロードのファイルは1時間30回（`EXPORT_DOWNLOADS_PER_HOUR`）です。集計API（`/stats/*`）は、キャッシュになく集計し直す呼び出しだけを、さらに1分30回に制限します（`STATS_COMPUTATIONS_PER_MINUTE`）。この構成では app コンテナが直接接続を受けるため、利用者の IP アドレスがそのまま使われます。将来 Cloudflare のプロキシなどを前段に置く場合は、`bootstrap/app.php` で `trustProxies` を設定しないと、全利用者が同じ IP として扱われます。
- **MySQL のメモリ**: `MYSQL_INNODB_BUFFER_POOL_SIZE` は 512MB 以上にしてください。これを下回ると、検索のたびにディスク読み込みが発生し、応答が 1〜2秒に遅くなります。メモリに余裕があるサーバーでは増やしてもかまいません。
