# 医療施設マスタAPI

全国8つの地方厚生局が公開する「保険医療機関・保険薬局の指定一覧」を毎日自動で取得・構造化し、
病院・診療所・歯科診療所・薬局の検索APIとして提供する Laravel アプリケーションです。

- 対象: 全国47都道府県・約22万施設（病院 / 診療所 / 歯科診療所 / 薬局）
- 更新: 各局サイトを毎日確認し、新しい版が公開されていれば自動で取込
- 提供: 認証不要・読み取り専用の REST API（OpenAPI 仕様書付き）

## 主な機能

- **施設の検索・詳細取得API** — 都道府県・施設種別・指定状態・地方厚生局・診療科目での絞り込みと、施設名・住所のあいまい検索
- **表記ゆれを吸収した検索** — 全角/半角、異体字（例: 髙→高）、番地の「ー」と「-」の混在を正規化して検索
- **8局の差異を吸収する取込パイプライン** — 局ごとに異なるページ構造・ファイル形式（単一xlsx / 複数シート / 県別zip 等）を個別のリゾルバ・展開処理で吸収
- **変更履歴の記録** — 新規指定・内容変更・廃止をイベントとして記録し、「いつ開業/廃止したか」を後から追跡可能

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

一覧APIの主なクエリパラメータ:

| パラメータ | 内容 |
|---|---|
| `q` | 施設名・住所のあいまい検索（表記ゆれを吸収） |
| `prefecture_code` | 都道府県コード（`01`〜`47`） |
| `institution_type` | 施設種別（1: 病院 / 2: 診療所 / 3: 歯科診療所 / 4: 薬局） |
| `status` | 指定状態（1: 指定中 / 2: 廃止 / 3: 休止） |
| `bureau_code` | 地方厚生局（1: 北海道 〜 8: 九州） |
| `department_category` | 診療科目の大分類（1: 内科 / 5: 眼科 など26分類） |
| `per_page` | 1ページの件数（既定25、最大100） |

種別・状態などの項目は `{"code": 値, "label": 日本語名}` の形で返します。`code` はそのまま対応する絞り込み条件に渡せます。

```http
GET /api/v1/medical-facilities?q=札幌&institution_type=1&per_page=1
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
    "attribution": { "notice": "本APIのデータは、各地方厚生局が公開する…", "sources": [ /* 8局の出典URL */ ] }
  }
}
```

- **仕様書**: 起動後に `/docs/api`（対話的に試せるUI）と `/docs/api.json`（OpenAPI）で確認できます。
- **レート制限**: IPアドレスごとに1分あたり60回です（`API_RATE_LIMIT_PER_MINUTE` で変更可）。
- **出典表示**: すべてのレスポンスの `meta.attribution` に、データの出典を含めています（後述の利用条件に対応）。

## データの取得・更新の仕組み

```
05:00  rhb:download   各局の一覧ページを確認し、新しい版のファイル（xlsx / zip）だけを保存
05:30  rhb:import     局×カテゴリ（医科・歯科・薬局）ごとの取込ジョブをバッチでキューに投入
          ↓ キューワーカー
       ImportRhbFacilityListJob
          1. ファイルを展開し、行を読み取って施設データに変換（Import 層）
          2. 施設ごとに新規作成 / 変更検出 / 再開を判定して保存し、イベントを記録（Sync 層）
          3. 今回のデータに載っていない施設を「廃止」にする（廃止検知）
```

- **取込済みはスキップ**: 取込済みのデータは翌日以降スキップします。パーサー変更時などは `rhb:import --force` で再取込できます。
- **行単位の失敗**: 1行の保存に失敗しても他の行の取込は続けます。その施設は廃止扱いにせず、ジョブを失敗として記録し、翌日に自動で再試行します。
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
- 全局の初回取込には時間がかかります（目安: 約230行/秒）。

起動後は次の URL で確認できます。
- API: http://localhost:8000/api/v1/medical-facilities
- 仕様書: http://localhost:8000/docs/api

定期実行（毎日 05:00 / 05:30）をローカルで動かす場合は、`vendor/bin/sail artisan schedule:work` を起動してください。

## テスト

```bash
vendor/bin/sail artisan test --compact
vendor/bin/sail bin pint          # コード整形
```

局ごとのリゾルバは、実際の一覧ページの HTML をフィクスチャ（`tests/Fixtures/`）にしてテストしています。

## データの出典・利用条件

本APIのデータは、各地方厚生局が公開する「保険医療機関・保険薬局の指定一覧」を加工して作成しています。
元データは[公共データ利用規約（第1.0版）](https://www.digital.go.jp/resources/open_data/public_data_license_v1.0)（PDL1.0）に準拠して公開されており、
利用にあたっては出典の記載と、加工した旨の記載が必要です。本APIはすべてのレスポンスにこれらを含めています。
詳細は [DATABASE.md の「データの出典・利用条件」](DATABASE.md#データの出典利用条件) を参照してください。
