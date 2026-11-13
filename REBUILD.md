# 月1回の作り直し

コードに変更がなくても、**月に1回**は本番のイメージを作り直します。

イメージは、ビルドした時点の PHP・OS（Debian）・MySQL のまま古くなっていきます。作り直すと、その間に出たセキュリティ修正が入ります。手順は「[DEPLOY.md](DEPLOY.md) 3. 更新のデプロイ」と同じです。ここには、それを月1回の作業として流す順番と、終わったことの確かめ方をまとめます。

- かかる時間：10分ほど（ビルドに数分、API が止まるのは長くて1分ほど）
- 作業する場所：サーバー（`ssh ubuntu@168.110.42.30`）の `~/medical-facility-master-api-laravel`

## 1. 時間を選ぶ

- **日本時間 05:00〜07:15 は避けます。** 毎日のデータの取得・取込・書き出しが動いています。
- 04:00 ごろ（毎日のバックアップ）と 00:15（アクセスログの確認）の前後も、避けると安心です。
- 日中なら、いつでも構いません。

## 2. サーバーに入る

```bash
ssh ubuntu@168.110.42.30
cd ~/medical-facility-master-api-laravel
```

## 3. 作り直す

1行が長いと、貼り付けたときに途中で切れることがあります。次の3つは、**1つずつ貼って、プロンプトが戻ってから次へ**進んでください。途中で Ctrl+C は押さないでください。

**① コードを最新にする**

```bash
git pull --ff-only
```

- `Already up to date.` なら、コードの変更はありません。そのまま②へ進みます。
- 新しいコミットが入った場合は、ふつうのデプロイを兼ねます。そのコミットの PR に「デプロイ」の欄で追加の作業が書かれていないかを確かめてから進みます。
- `fatal: Not possible to fast-forward` などで失敗したら、サーバーで誰かがファイルを書き換えています。ここで止めて、原因を確かめます。

**② イメージを作り直し、MySQL のイメージを取り直し、バックアップを取る**

```bash
docker compose build --pull && docker compose pull mysql && ~/backup-db.sh
```

- 数分かかります。しばらく何も表示されなくても、そのまま待ちます。
- 最後に、バックアップのファイル（`mf-<日付>.sql.gz`、約75MB）が表示されれば成功です。

**③ コンテナを新しいイメージで作り直す**

```bash
docker compose run --rm scheduler php artisan migrate --force && docker compose up -d
```

- **この行を忘れると、新しいイメージを作っただけで、動いているコンテナは古いまま**です。
- `Nothing to migrate.` のあと、`app`・`worker`・`scheduler` が `Started` になれば成功です。
- MySQL に新しい版があったときは、`mysql` も作り直されます。そのときは、30秒〜1分ほど API がエラー（500）を返します。worker は自動で起動し直します。

## 4. 終わったことを確かめる

サーバーで、次の3つを実行します。

```bash
# すべて動いていて、app・worker・scheduler の「Up」が数分以内になっていること（作り直された印）
docker compose ps

# イメージを作った日時が、今日になっていること
docker image ls medical-facility-master-api

# 直近のログに、エラーがないこと（何も表示されなければ問題なし）
docker compose logs --since 10m app worker scheduler | grep -iE "exception|\.ERROR"
```

手元のパソコンから、API が答えることも確かめます。

```bash
curl -s -o /dev/null -w "%{http_code}\n" https://168-110-42-30.sslip.io/up
curl -s -o /dev/null -w "%{http_code}\n" "https://168-110-42-30.sslip.io/api/v1/medical-facilities?per_page=1"
```

どちらも `200` なら完了です。ブラウザで https://168-110-42-30.sslip.io/ を開き、トップページが表示されることも見ておくと安心です。

## うまくいかなかったとき

- **②でビルドが失敗した**：動いているコンテナはそのままなので、サイトは止まっていません。エラーの出力を残して、原因を調べます。
- **③のあと API が 500 のまま、1分たっても戻らない**：`docker compose ps` で `mysql` が `healthy` かを見ます。`docker compose logs --since 10m mysql app` のエラーを確かめます。
- **元に戻したい**：①で新しいコミットが入っていたなら、そのコミットに原因があることが多いです。前のコミットに戻してから、②・③をやり直します（DEPLOY.md「3. 更新のデプロイ」）。データベースのバックアップは②で取れています。

## 一般向けサイトについて

一般向けサイト（いま開いてる病院・薬局、`~/open-clinic-finder`）は、作った静的なファイルを app コンテナが配信しているだけです。そのため、月1回の作り直しは要りません。中身を更新したときだけ、DEPLOY.md「一般向けサイト（/）」の手順でビルドします。
