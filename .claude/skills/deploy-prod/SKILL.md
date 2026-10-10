---
name: deploy-prod
description: "Deploy merged main of this API to the production Oracle A1 server over SSH and verify it. Use only when the user says /deploy-prod, or right after merging an API PR the user approved with「お願いします」(that approval covers the deploy). Never deploy on your own initiative."
---

# /deploy-prod — 本番へのデプロイ

手順の正本は [DEPLOY.md](../../../DEPLOY.md)「3. 更新のデプロイ」。ここには、その手順を安全に流すための判断を書く。

- サーバー：`ssh ubuntu@168.110.42.30`（WSL から。鍵が使えないときはユーザーに `ssh-add ~/.ssh/id_ed25519` を頼む）
- リポジトリ：サーバーの `~/medical-facility-master-api-laravel`
- 公開 URL：`https://168-110-42-30.sslip.io`
- サーバーの時計は UTC。時刻の判断は日本時間（JST）で行う。

## 1. 事前の確認

1. **時間帯**：日本時間 **05:00〜07:15 は避ける**（取得・取込・座標・営業時間の割り当て・書き出しが続く）。その時間なら、終わるまで待つかユーザーに確認する。
2. **出すもの**：ローカルで `git fetch` し、サーバーの HEAD から `origin/main` までのコミットを確かめる。
   ```bash
   ssh ubuntu@168.110.42.30 'cd ~/medical-facility-master-api-laravel && git rev-parse HEAD'
   git log --oneline <サーバーのHEAD>..origin/main
   ```
3. **一度だけの作業**：含まれる PR の本文の「デプロイ」欄と DEPLOY.md の差分を読み、追加の作業（`.env` の追記、取込のやり直し、`--force` のコマンドなど）を洗い出す。
   - **`.env` の変更が必要なら、ユーザーに頼む**（秘密情報は Claude から見ない・書かない）。終わるまで先へ進まない。
4. **メンテナンスモードが要るか**：時間のかかる migrate やデータの入れ直しで、API が正しく答えられない時間があるなら使う（DEPLOY.md「メンテナンス中の表示」）。通常の更新では使わない。

## 2. デプロイ

```bash
ssh ubuntu@168.110.42.30 'cd ~/medical-facility-master-api-laravel && git pull --ff-only && docker compose build && docker compose run --rm app php artisan migrate --force && docker compose up -d'
```

- `git pull --ff-only` が失敗したら、サーバーで手作業の変更がないか確かめ、ユーザーに相談する。
- 続けて、1-3 で洗い出した一度だけの作業を、PR に書いた順で実行する。
- 長いコマンド（取込のやり直しなど）は `run_in_background` で実行し、終わるのを待つ。
- メンテナンスモードにした場合は、**最後に必ず `php artisan up`**。

## 3. 確認

```bash
curl -fsS https://168-110-42-30.sslip.io/up
curl -fsS "https://168-110-42-30.sslip.io/api/v1/medical-facilities?per_page=1" | head -c 300
ssh ubuntu@168.110.42.30 'cd ~/medical-facility-master-api-laravel && docker compose ps && docker compose logs --since 5m app worker scheduler | tail -50'
```

- すべてのコンテナが `running`（mysql は `healthy`）で、ログに例外がないこと。
- 変更した機能そのものを、本番の URL で1回は呼んで確かめる（例：新しいパラメータを付けた一覧）。
- 一般向けサイト（`/`）とデモ用フロントエンドに影響する変更なら、それらでも確かめる。

## 4. 報告

- デプロイしたコミット、実行した一度だけの作業、確認の結果を短く伝える。
- ユーザーに残る作業があれば「⏸ あなたの操作待ち：…」で終える。なければ「完了」と書いて終える。

## 失敗したとき

- migrate や build が失敗したら、それ以上進めず、エラーをそのまま伝える。
- 元に戻すときは、サーバーで `git checkout <前のHEAD>` → build → `up -d`。migrate を巻き戻す（`migrate:rollback`）のは、データが消えないか確かめたうえで、ユーザーの承認を得てから。
