# 医療施設マスタAPI

全国8つの地方厚生局が公開する「保険医療機関・保険薬局の指定一覧」を毎日自動で取得・構造化し、
病院・診療所・歯科診療所・薬局の検索APIとして提供する Laravel アプリケーションです。

- 対象: 全国47都道府県・約22万施設（病院 / 診療所 / 歯科診療所 / 薬局）
- 更新: 各局サイトを毎日確認し、新しい版が公開されていれば自動で取込
- 提供: 認証不要・読み取り専用の REST API（OpenAPI 仕様書付き）

## 主な機能

- **施設の検索・詳細取得API** — 都道府県・施設種別・指定状態・地方厚生局・診療科目・指定年月日での絞り込みと、施設名・住所のあいまい検索
- **表記ゆれを吸収した検索** — 全角/半角、異体字（例: 髙→高）、番地の「ー」と「-」の混在を正規化して検索
- **8局の差異を吸収する取込パイプライン** — 局ごとに異なるページ構造・ファイル形式（単一xlsx / 複数シート / 県別zip 等）を個別のリゾルバ・展開処理で吸収
- **変化の記録と履歴API** — 毎月の公開データを比較して新規・廃止・変更を記録し、全国の変化の一覧と施設ごとの履歴として提供（取込開始時の全件や取り込み直しによる差分は、実際の変化と区別）
- **新規開業の検索** — 指定年月日と登録理由（新規・交代・移転など）で絞り込み、「今月新規開業した施設」を取得

## 技術スタック

| 分類 | 使用技術 |
|---|---|
| 言語・フレームワーク | PHP 8.5 / Laravel 13 |
| データベース | MySQL 8.4 |
| 非同期処理 | Laravel Queue（database ドライバ）+ Job Batching |
| API仕様書 | [dedoc/scramble](https://scramble.dedoc.co/)（FormRequest / API Resource から OpenAPI を自動生成） |
| 開発環境 | Laravel Sail（Docker） |
| テスト・整形 | PHPUnit / Laravel Pint |

## API

| メソッド | パス | 内容 |
|---|---|---|
| GET | `/api/v1/medical-facilities` | 施設一覧・検索（ページネーション付き） |
| GET | `/api/v1/medical-facilities/{id}` | 施設詳細 |
| GET | `/api/v1/medical-facilities/{id}/events` | 施設の履歴（新規・廃止・変更） |
| GET | `/api/v1/medical-facility-events` | 全国の変化の一覧（ページネーション付き） |
| GET | `/api/v1/options` | 絞り込みの選択肢（都道府県・施設種別・診療科目など） |

一覧APIの主なクエリパラメータ:

| パラメータ | 内容 |
|---|---|
| `q` | 施設名・住所のあいまい検索（表記ゆれを吸収） |
| `prefecture_code` | 都道府県コード（`01`〜`47`） |
| `institution_type` | 施設種別（1: 病院 / 2: 診療所 / 3: 歯科診療所 / 4: 薬局） |
| `status` | 指定状態（1: 指定中 / 2: 廃止 / 3: 休止） |
| `bureau_code` | 地方厚生局（1: 北海道 〜 8: 九州） |
| `department_category` | 診療科目の大分類（1: 内科 / 5: 眼科 など26分類） |
| `designated_from` / `designated_to` | 指定年月日の範囲（`YYYY-MM-DD`、両端を含む） |
| `designation_reason` | 登録理由（`新規` / `交代` / `組織変更` / `移転` など） |
| `sort` | 並び順（`designated_on`: 指定年月日の古い順、`-designated_on`: 新しい順。省略時は id 順） |
| `per_page` | 1ページの件数（既定25、最大100） |

種別・状態などの項目は `{"code": 値, "label": 日本語名}` の形で返します。`code` はそのまま対応する絞り込み条件に渡せます。絞り込み画面の選択肢は `/api/v1/options` でまとめて取得できます（1日キャッシュ可能、ETag 対応）。

```jsonc
// GET /api/v1/options
{
  "data": {
    "prefectures": [{ "code": "01", "label": "北海道", "bureau": { "code": 1, "label": "北海道厚生局" } } /* …47件 */],
    "institution_types": [{ "code": 1, "label": "病院" } /* … */],
    "statuses": [/* … */], "bureaus": [/* … */], "department_categories": [/* 26分類 */],
    "event_types": [{ "code": 1, "label": "新規" }, { "code": 2, "label": "廃止" }, { "code": 3, "label": "変更" }],
    "designation_reasons": ["新規", "組織変更", "交代", "移動", "移転", "その他", "継承"]  // 代表的な値（元データは自由記述）
  }
}
```

```http
GET /api/v1/medical-facilities?q=札幌&institution_type=1&per_page=1
```

たとえば「2026年8月に新規開業した施設（新しい順）」は次のように取得できます。登録理由が `交代`（開設者の交代）や `移転` の施設は、指定年月日が新しくても新規開業ではないため、`designation_reason=新規` で除外します。

```http
GET /api/v1/medical-facilities?designated_from=2026-08-01&designated_to=2026-08-31&designation_reason=新規&sort=-designated_on
```

```jsonc
{
  "data": [
    {
      "id": 1,
      "facility_code": "0112489",
      "institution_type": { "code": 1, "label": "病院" },
      "status": { "code": 1, "label": "指定中" },
      "bureau": { "code": 1, "label": "北海道厚生局" },
      "name": "医療法人　愛全病院",
      "prefecture_code": "01",
      "prefecture": { "code": "01", "label": "北海道" },
      "postal_code": "005-0813",
      "address": "札幌市南区川沿１３条２丁目１番３８号",
      "phone_number": "011-571-5670",
      "bed_counts": { "一般": 231, "療養": 206 },
      "department_categories": [
        { "code": 1, "label": "内科" },
        { "code": 9, "label": "リハビリテーション科" }
      ]
      // ...
    }
  ],
  "meta": {
    "current_page": 1,
    "total": 196,
    "attribution": {
      "notice": "本APIのデータは、各地方厚生局が公開する…を加工して作成しています。",
      "license": { "name": "公共データ利用規約（第1.0版）", "url": "https://www.digital.go.jp/…" },
      "disclaimer": "データの正確性・完全性は保証しません。…",
      "sources": [ /* 8局の出典URL */ ]
    }
  }
}
```

### 変化の一覧・施設の履歴

毎月の公開データを前月分と比較して、施設の**新規**・**廃止**・**変更**を記録しています。

```http
GET /api/v1/medical-facility-events?event_type=1&prefecture_code=13&occurred_from=2026-10-01
```

```jsonc
{
  "data": [
    {
      "id": 230001,
      "event_type": { "code": 3, "label": "変更" },
      "origin": { "code": 2, "label": "検知" },
      "occurred_on": "2026-10-01",
      "changes": [
        { "attribute": "status", "old": { "code": 1, "label": "指定中" }, "new": { "code": 3, "label": "休止" } },
        { "attribute": "phone_number", "old": "03-1111-1111", "new": "03-2222-2222" }
      ],
      "facility": { "id": 1234, "name": "…" /* 施設詳細と同じ形 */ }
    }
  ]
}
```

- **絞り込み**: `event_type`（1: 新規 / 2: 廃止 / 3: 変更）、`occurred_from` / `occurred_to`、施設の `prefecture_code` / `institution_type`、`per_page`
- **`occurred_on` の意味**: 変化が載った公開データの日付です（各局は月1回更新）。実際の開業日・廃止日ではありません。開業日を知りたい場合は、施設の `designated_on`（指定年月日）を使ってください。
- **記録の種類（`origin`）**: 変化の一覧は、公開データ間で見つかった変化（検知）だけを返します。取込を始めた時点の全施設（初回取込）と、取り込み直しによる差分（再処理）は含みません。施設の履歴では、初回取込を「掲載開始」の記録として含めます。
- **再開**: 過去に廃止された施設が再び掲載された「新規」には `is_reopening: true` が付きます。
- **個人名**: 開設者名・管理者名の変更は、`changes` に含めません。
- 記録は運用を始めてから蓄積されるため、最初の1〜2か月は変化の一覧が空になります。

### 共通

- **仕様書**: 起動後に `/docs/api`（対話的に試せるUI）と `/docs/api.json`（OpenAPI）で確認できます。
- **レート制限**: IPアドレスごとに1分あたり60回です（`API_RATE_LIMIT_PER_MINUTE` で変更可）。
- **出典表示**: すべてのレスポンスの `meta.attribution` に、データの出典・利用条件（PDL1.0）・免責を含めています（後述の「データの出典・利用条件」を参照）。

## データの取得・更新の仕組み

```
05:00  rhb:download   各局の一覧ページを確認し、新しい版のファイル（xlsx / zip）だけを保存
05:30  rhb:import     局×カテゴリ（医科・歯科・薬局）ごとの取込ジョブをバッチでキューに投入
          ↓ キューワーカー
       ImportRhbFacilityListJob
          1. ファイルを展開し、行を読み取って施設データに変換（Import 層）
          2. 施設ごとに新規作成 / 変更検出 / 再開を判定して保存し、イベントを記録（Sync 層）
          3. 今回のデータに載っていない施設を「廃止」にする（廃止検知）
07:00  rhb:status     すべての局・カテゴリが取込済みで最新かを確認
```

- **取込済みはスキップ**: 取込済みのデータは翌日以降スキップします。パーサー変更時などは `rhb:import --force` で再取込できます。
- **行単位の失敗**: 1行の保存に失敗しても他の行の取込は続けます。その施設は廃止扱いにせず、ジョブを失敗として記録し、翌日に自動で再試行します。
- **監視**: 取得の失敗（局のサイトの構造変更を含む）と、取込の失敗・データの更新停止を、[healthchecks.io](https://healthchecks.io/) 経由で通知します（設定は [DEPLOY.md](DEPLOY.md) の「監視」）。
- **テーブル設計**: 詳細は [DATABASE.md](DATABASE.md) を参照してください。

```
app/Services/Rhb/
├── Download/   局ごとの一覧ページ解析（LinkResolver）と、ファイル展開（BundleExpander）
├── Import/     xlsx の読み取りと、住所・電話番号・指定履歴・診療科目などの項目変換
└── Sync/       施設データの保存・変更検出と、廃止検知
```

## セットアップ（ローカル開発）

Docker が必要です。

```bash
git clone https://github.com/TomonoriYoshida/medical-facility-master-api-laravel.git
cd medical-facility-master-api-laravel
cp .env.example .env

# Composer 依存のインストール（ホストに PHP / Composer がなくても可）
docker run --rm -u "$(id -u):$(id -g)" -v "$(pwd):/app" -w /app composer:2 install --ignore-platform-reqs

vendor/bin/sail up -d
vendor/bin/sail artisan key:generate
vendor/bin/sail artisan migrate --seed   # 異体字マスタ（kanji_variants）の投入を含む
```

データを取り込みます。取込はキューで実行されるので、別のターミナルでワーカーを起動してから実行してください。

```bash
vendor/bin/sail artisan queue:work                  # 別ターミナル

vendor/bin/sail artisan rhb:download                # 全局の最新ファイルを取得
vendor/bin/sail artisan rhb:import --wait           # 取込（完了まで待機して結果を表示）
```

- `--bureau=hokkaido` のように局を指定すると、その局だけを処理できます（局キーは [config/rhb.php](config/rhb.php) を参照）。
- このリポジトリをもとに自分の環境で運用する場合は、`rhb:download` が送る User-Agent（[DownloadRhbDatasets.php](app/Console/Commands/DownloadRhbDatasets.php) の `USER_AGENT`）を、自分の連絡先に書き換えてください。各局のサーバーからは、このリポジトリからのアクセスとして見えるためです。
- 全局の初回取込には時間がかかります（目安: 約230行/秒）。

起動後は次の URL で確認できます。
- API: http://localhost:8000/api/v1/medical-facilities
- 仕様書: http://localhost:8000/docs/api

定期実行（毎日 05:00 / 05:30）をローカルで動かす場合は、`vendor/bin/sail artisan schedule:work` を起動してください。

## 本番デプロイ

サーバー1台に Docker Compose で構築します（FrankenPHP による HTTPS の自動化、キューワーカー、スケジューラ、MySQL を含む）。手順は [DEPLOY.md](DEPLOY.md) を参照してください。

## テスト

```bash
vendor/bin/sail artisan test --compact
vendor/bin/sail bin pint          # コード整形
vendor/bin/sail php vendor/bin/phpstan analyse --memory-limit=1G   # 静的解析（Larastan、設定は phpstan.neon）
```

プルリクエストでは、GitHub Actions がこの3つ（Pint / PHPStan / PHPUnit）を実行します。

局ごとのリゾルバは、実際の一覧ページの HTML をフィクスチャ（`tests/Fixtures/`）にしてテストしています。

## データの出典・利用条件

本APIのデータは、各地方厚生局が公開する「保険医療機関・保険薬局の指定一覧」を加工して作成しています。
元データは[公共データ利用規約（第1.0版）](https://www.digital.go.jp/resources/open_data/public_data_license_v1.0)（PDL1.0）に準拠して公開されています。
**本APIが返すデータも、同じくPDL1.0に準拠して提供します。** 商用・非商用を問わず利用できますが、次の点を守ってください。

1. **出典を記載する**: 例）出典：各地方厚生局「保険医療機関・保険薬局の指定一覧」
2. **加工したことを記載する**: 例）各地方厚生局「保険医療機関・保険薬局の指定一覧」を加工して作成
3. **国（厚生労働省・地方厚生局）が作成したかのような形で公表・利用しない**

各局の出典URLは、すべてのレスポンスの `meta.attribution.sources` に含めています。

- **免責**: データの正確性・完全性は保証しません。最新かつ正確な情報は、各地方厚生局の公表資料を確認してください。
- **個人名は提供しません**: 元データの開設者名・管理者名は個人名を含むため、APIでは返しません。

詳細は [DATABASE.md の「データの出典・利用条件」](DATABASE.md#データの出典利用条件) を参照してください。

## ライセンス

ソースコードは [MIT License](LICENSE) で公開しています。商用・非商用を問わず、自由に利用・改変・再配布できます。

このリポジトリをもとに開発される場合は、README などに「[Tomonori Yoshida のリポジトリ](https://github.com/TomonoriYoshida/medical-facility-master-api-laravel)をベースに開発」とクレジットを記載していただけると嬉しいです（ライセンス上の義務ではなく、お願いです）。

API が返すデータの利用条件は、ソースコードのライセンスとは別です。前述の「データの出典・利用条件」を参照してください。

### サードパーティのデータ

異体字の対応表 [database/seeders/data/itaiji-mapping.csv](database/seeders/data/itaiji-mapping.csv) は、Unicode コンソーシアムの [Unihan データベース](https://www.unicode.org/charts/unihan.html)（`kJapaneseOldVariant` / `kJapaneseNewVariant`）から抽出したデータを含みます。このデータは [Unicode License v3](database/seeders/data/LICENSE-Unicode.txt) に従います。
