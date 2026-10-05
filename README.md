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
| GET | `/api/v1/medical-facilities/{id}/opening-hours` | 施設の診療時間・休診日（厚生労働省「医療情報ネット」） |
| GET | `/api/v1/medical-facility-events` | 全国の変化の一覧（ページネーション付き） |
| GET | `/api/v1/stats/facilities` | 施設数の集計（月・市区町村・診療科目ごと） |
| GET | `/api/v1/stats/facility-events` | 新規・廃止の件数の集計（月・市区町村ごと） |
| GET | `/api/v1/options` | 絞り込みの選択肢（都道府県・施設種別・診療科目など） |
| GET | `/api/v1/exports` | 一括ダウンロードのファイル一覧（都道府県ごと・全体の CSV / JSON Lines） |

一覧APIの主なクエリパラメータ:

| パラメータ | 内容 |
|---|---|
| `medical_institution_code` | 10桁の医療機関コード（カンマ区切りで最大100件） |
| `q` | 施設名・住所のあいまい検索（表記ゆれを吸収）。空白で区切ると、すべての語を含む施設（例: `札幌 眼科`、最大5語） |
| `prefecture_code` | 都道府県コード（`01`〜`47`） |
| `institution_type` | 施設種別（1: 病院 / 2: 診療所 / 3: 歯科診療所 / 4: 薬局） |
| `status` | 指定状態（1: 指定中 / 2: 廃止 / 3: 休止） |
| `bureau_code` | 地方厚生局（1: 北海道 〜 8: 九州） |
| `department_category` | 診療科目の大分類（1: 内科 / 5: 眼科 など26分類） |
| `designated_from` / `designated_to` | 指定年月日の範囲（`YYYY-MM-DD`、両端を含む） |
| `designation_reason` | 登録理由（`新規` / `交代` / `組織変更` / `移転` など） |
| `latitude` / `longitude` / `radius` | 近隣検索。指定した地点から `radius` メートル以内（既定1000、最大20000）の施設を近い順に返し、各施設に `distance`（メートル）を付ける。`sort` と併用するとその順 |
| `municipality_code` | 市区町村コード（5桁、例: `13101` 千代田区） |
| `updated_since` | この日時以降に内容が変わった施設（ISO 8601、例: `2026-10-01T05:00:00Z`。廃止・再開も含む） |
| `sort` | 並び順（`designated_on` / `-designated_on`: 指定年月日の古い順 / 新しい順、`updated_at` / `-updated_at`: 内容が変わった日時の古い順 / 新しい順。省略時は id 順） |
| `per_page` | 1ページの件数（既定25、最大100） |
| `page` | ページ番号。**最初の1万件まで**（上限は `meta.max_page`。`per_page=100` なら100ページ）。それより先は422 |
| `pagination=cursor` | カーソル方式のページ送り（件数の上限なし）。次のページは `links.next` で取得する。id 順か `sort=updated_at` のときだけ使える |

種別・状態などの項目は `{"code": 値, "label": 日本語名}` の形で返します。`code` はそのまま対応する絞り込み条件に渡せます。絞り込み画面の選択肢は `/api/v1/options` でまとめて取得できます（1日キャッシュ可能、ETag 対応）。

```jsonc
// GET /api/v1/options
{
  "data": {
    "prefectures": [{ "code": "01", "label": "北海道", "bureau": { "code": 1, "label": "北海道厚生局" } } /* …47件 */],
    "institution_types": [{ "code": 1, "label": "病院" } /* … */],
    "statuses": [/* … */], "bureaus": [/* … */], "department_categories": [/* 26分類 */],
    "event_types": [{ "code": 1, "label": "新規" }, { "code": 2, "label": "廃止" }, { "code": 3, "label": "変更" }],
    "geocode_levels": [{ "code": 1, "label": "住居" } /* 街区・地番・地番（枝番なし）・町丁目・医療情報ネット */],
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
      "medical_institution_code": "0110112489",  // 全国で一意な10桁のコード
      "institution_type": { "code": 1, "label": "病院" },
      "status": { "code": 1, "label": "指定中" },
      "bureau": { "code": 1, "label": "北海道厚生局" },
      "name": "医療法人　愛全病院",
      "prefecture_code": "01",
      "prefecture": { "code": "01", "label": "北海道" },
      "municipality": { "code": "01107", "label": "札幌市南区" },
      "postal_code": "005-0813",
      "address": "札幌市南区川沿１３条２丁目１番３８号",
      "location": {  // 住所から求めた座標（求められなかった施設は null）
        "latitude": 42.96, "longitude": 141.32,
        "level": { "code": 2, "label": "街区" }  // 精度: 住居 / 街区 / 地番 / 地番（枝番なし） / 町丁目 / 医療情報ネット
      },
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
      "sources": [ /* 8局の出典URL */ ],
      "address_source": { "name": "アドレス・ベース・レジストリ（デジタル庁）…", "url": "https://catalog.registries.digital.go.jp/rc/dataset/" }
    }
  }
}
```

### 一括ダウンロード

全件を API で取得すると全国で約2,250回の呼び出しになるため、毎日の取込の後に、取り扱う範囲の全施設（廃止を含む）をファイルで提供しています。

```jsonc
// GET /api/v1/exports
{
  "data": {
    "generated_at": "2026-10-01T22:10:05.000000Z",
    "data_updated_at": "2026-10-01T20:41:12.000000Z",   // ファイル内で最も新しい updated_at
    "files": [
      { "name": "medical-facilities-13.csv.gz", "format": "csv", "prefecture": { "code": "13", "label": "東京都" },
        "records": 23810, "size": 1180000, "sha256": "…", "url": "https://…/api/v1/exports/medical-facilities-13.csv.gz" },
      /* …都道府県ごとの csv / jsonl と、全体の medical-facilities-all.csv.gz / .jsonl.gz */
    ]
  }
}
```

- **形式**: どちらも gzip 圧縮、UTF-8（BOM なし）です。
  - **JSON Lines**（`.jsonl.gz`）: 1行1施設。施設詳細 API と同じ形です。
  - **CSV**（`.csv.gz`）: 見出し行付き、RFC 4180 形式。種別などはコードと名前を別の列にし、診療科目は `|` 区切り（例: `1|5` と `内科|眼科`）、`designation_history` と `bed_counts` は JSON 文字列です。表計算ソフトで数式として実行されないよう、`=` `+` `-` `@` で始まる文字列には先頭に `'` を付けます（JSON Lines は元の値のまま）。
- **更新**: 毎日 07:10（日本時間）に、データが変わっていれば作り直します。変わっていなければファイルも `ETag`（SHA-256）も変わりません。
- **検証**: ダウンロードしたファイルは `sha256` で検証できます。`If-None-Match` を付けると、変わっていなければ 304 を返します。
- **差分との組み合わせ**: ファイルで全件を取り込んだあと、`data_updated_at` を起点に `updated_since` で差分を取得できます（次の節）。
- 出典表示は `GET /api/v1/exports` の `meta.attribution` にあります。ファイルを再配布する場合も、出典の表示が必要です（「データの出典・利用条件」を参照）。

### 差分の同期

施設データを自分のシステムに取り込んで使う場合は、初回に全件を取得したあと、変わった施設だけを取得できます。

1. 初回: 一括ダウンロードのファイルで全件を取り込み、`data_updated_at` を保存する（API で取得する場合は `pagination=cursor&sort=updated_at&per_page=100` で `links.next` が `null` になるまでたどり、最も新しい `updated_at` を保存する）
2. 以降: `GET /api/v1/medical-facilities?updated_since={保存した updated_at}&sort=updated_at&pagination=cursor&per_page=100` で変わった施設だけを取得し、`links.next` が `null` になるまでたどって、`id` で上書きする

- **ページ番号（`page`）ではなく、カーソル方式（`pagination=cursor`）を使ってください。** ページ番号は最初の1万件までしか進めず、パーサーの修正などで1日に1万件以上が変わることもあります。カーソル方式は「前のページの最後の施設（`updated_at` と `id`）より後」を取得するので、件数に上限がなく、同じ時刻に更新された施設が何百件あっても取りこぼしません（取込は1秒に数百件を保存するため、`updated_since` の時刻を進めていく方法では先へ進めないことがあります）。

- `updated_at` は施設の内容が実際に変わったとき（廃止・再開と、座標の追加・変更を含む）だけ更新され、変化のない取込では変わりません。廃止された施設は削除されず、`status` が「廃止」になります。
- `updated_since` はその日時ちょうどの施設も含むため、前回の最後の施設が再び返ることがあります。`id` で上書きすれば問題ありません。
- データが変わるのは毎日の取込と座標の付与（日本時間 05:00〜07:00 頃）のときだけです。この時間を避けて同期すると、ページの途中でデータが変わることはありません。

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
      "detected_at": "2026-10-15T20:30:12.000000Z",
      "changes": [
        { "attribute": "status", "old": { "code": 1, "label": "指定中" }, "new": { "code": 3, "label": "休止" } },
        { "attribute": "phone_number", "old": "03-1111-1111", "new": "03-2222-2222" }
      ],
      "facility": { "id": 1234, "name": "…" /* 施設詳細と同じ形 */ }
    }
  ]
}
```

- **絞り込み**: `event_type`（1: 新規 / 2: 廃止 / 3: 変更）、`occurred_from` / `occurred_to`、`detected_since`、施設の `prefecture_code` / `institution_type`、`per_page`
- **`occurred_on` の意味**: 変化が載った公開データの日付です（各局は月1回更新）。実際の開業日・廃止日ではありません。開業日を知りたい場合は、施設の `designated_on`（指定年月日）を使ってください。
- **前回の確認以降の変化**: 公開データは日付の数日〜数週間後に取り込むため、`occurred_on` で絞ると取りこぼします。`detected_at`（取り込んで検知した日時）を保存し、次回は `detected_since`（ISO 8601）に渡してください。
- **記録の種類（`origin`）**: 変化の一覧は、公開データ間で見つかった変化（検知）だけを返します。取込を始めた時点の全施設（初回取込）と、取り込み直しによる差分（再処理）は含みません。施設の履歴では、初回取込を「掲載開始」の記録として含めます。
- **再開**: 過去に廃止された施設が再び掲載された「新規」には `is_reopening: true` が付きます。
- **個人名**: 開設者名・管理者名の変更は、`changes` に含めません。
- 記録は運用を始めてから蓄積されるため、最初の1〜2か月は変化の一覧が空になります。

### 診療時間・休診日

厚生労働省「医療情報ネット」のオープンデータ（年2回、6月・12月に更新）から、施設の診療時間と定休日を返します。

```http
GET /api/v1/medical-facilities/1234/opening-hours
```

```jsonc
{
  "data": {
    "published_on": "2026-06-01",
    "schedules": [
      {
        "departments": ["内科", "小児科"],  // 同じ診療時間の診療科をまとめたもの（薬局は空）
        "slots": [
          {
            "number": 1,  // 時間帯（午前・午後など）
            "days": [
              { "day": "mon", "opens": "09:00", "closes": "12:30", "reception_opens": "08:45", "reception_closes": "12:00" }
              // day: mon〜sun、holiday（祝日）。診療しない曜日は含まない
            ]
          }
        ]
      }
    ],
    "closures": {
      "weekly": ["sun"],                       // 毎週の休み
      "monthly": [{ "week": 2, "day": "wed" }], // 決まった週の休み（第2水曜）
      "holidays": true,                        // 祝日に休むか（不明は null）
      "other": "年末年始"                       // その他（自由記述）
    }
  }
}
```

- **照合**: 医療情報ネットの施設は厚生局のデータと共通のコードを持たないため、同じ市区町村・施設種別で名称（または所在地）が一致する施設が1つだけ見つかったときに返します。見つからないときは `data` が `null` です。照合できる施設は、病院・薬局で約94%、診療所・歯科診療所で約80%です（2026年6月版）。
- **時刻**: 公開データのまま `HH:MM` で返します。終了が開始より早いもの（夜間など）もそのままです。受付時間は時間帯をまたいで1つの時間帯にだけ入っていることがあります。
- **鮮度**: `published_on` の時点の情報です。臨時の休診や最近の変更は含まないため、画面に出すときは「最新の情報は医療機関に確認してください」などの注意書きを添えてください。

### 施設数の集計

絞り込んだ施設を、月・市区町村・診療科目ごとに数えます。絞り込みの条件は一覧APIと同じです（`prefecture_code`・`municipality_code`・`institution_type`・`status`・`department_category`・`designation_reason`・`designated_from` / `designated_to`）。

```http
GET /api/v1/stats/facilities?group_by=month&designation_reason=新規&designated_from=2025-10-01&designated_to=2026-09-30&prefecture_code=13
```

```jsonc
{
  "data": [
    { "key": "2025-10", "label": "2025年10月", "count": 92 },
    { "key": "2025-11", "label": "2025年11月", "count": 87 }
    // …期間内のすべての月
  ],
  "meta": { "total": 926, "group_by": "month", "attribution": { /* 出典 */ } }
}
```

- **`group_by`**: `month`（指定年月日の月。`designated_from` / `designated_to` が必須で60か月まで、施設のない月は0）、`municipality`（市区町村コード。判定できない施設は `key` が null）、`department_category`（診療科目のコード）。`month` 以外は件数の多い順です。
- **新規開業の数え方**: `designation_reason=新規` と指定年月日の期間を組み合わせます。保険医療機関の指定は6年ごとに更新されますが、指定年月日は最初の指定日のままです。
- **診療科目**: 1つの施設が複数の診療科目に数えられるため、`count` の合計は `meta.total`（絞り込んだ施設数）と一致しません。
- **人口あたりの件数**: `municipality` のときは、各市区町村の人口（総務省「住民基本台帳に基づく人口」、`meta.population_as_of` 時点）と、人口1万人あたりの件数（`count_per_10k`）も返します。住民登録上の人口のため、昼間人口の多い都心部（千代田区など）や人口の少ない町村では極端な値になります。ほかの `group_by` では null です。
- **キャッシュ**: データは1日1回しか変わらないため、結果をサーバー側で1時間キャッシュし、`Cache-Control: public, max-age=3600` を付けて返します。取込の直後は、最大1時間前の集計が返ることがあります。

### 新規・廃止の集計

変化の一覧と同じ記録（公開データ間で検知した新規・廃止）を、月・市区町村ごとに数えます。地域の開業と廃業の推移を比べるためのものです。

```http
GET /api/v1/stats/facility-events?group_by=month&event_type=2&occurred_from=2025-11-01&occurred_to=2026-10-31&prefecture_code=13
```

- **`event_type`**（必須）: 1（新規）または 2（廃止）。
- **`group_by`**: `month`（変化が載った公開データの月。`occurred_from` / `occurred_to` が必須で60か月まで、変化のない月は0）、`municipality`（施設の市区町村。件数の多い順）。
- **施設の絞り込み**: `prefecture_code`・`municipality_code`・`institution_type`・`department_category` は、施設の現在の内容で判定します。
- 記録は運用を始めてから蓄積されるため、最初の1〜2か月は0件です。レスポンスの形とキャッシュは施設数の集計と同じです。

### 共通

- **仕様書**: 起動後に `/docs/api`（対話的に試せるUI）と `/docs/api.json`（OpenAPI）で確認できます。
- **レート制限**: IPアドレスごとに1分あたり60回です（`API_RATE_LIMIT_PER_MINUTE` で変更可）。集計API は、新しい条件で集計し直す呼び出しだけをさらに1分あたり30回に制限します（1時間キャッシュされた結果は数えません。`STATS_COMPUTATIONS_PER_MINUTE` で変更可）。
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
毎月2日 04:30  medical-info-net:import  医療情報ネットの座標・診療時間・休診日を取り込む（年2回の更新時だけ）
06:30  facilities:geocode  新規・移転した施設の座標を住所から求める（アドレス・ベース・レジストリ）
07:00  rhb:status     すべての局・カテゴリが取込済みで最新かを確認
07:10  rhb:export     一括ダウンロードのファイルを作成（データが変わったときだけ）
```

- **取込済みはスキップ**: 取込済みのデータは翌日以降スキップします。パーサー変更時などは `rhb:import --force` で再取込できます。
- **行単位の失敗**: 1行の保存に失敗しても他の行の取込は続けます。その施設は廃止扱いにせず、ジョブを失敗として記録し、翌日に自動で再試行します。
- **監視**: 取得の失敗（局のサイトの構造変更を含む）と、取込の失敗・データの更新停止を、[healthchecks.io](https://healthchecks.io/) 経由で通知します（設定は [DEPLOY.md](DEPLOY.md) の「監視」）。
- **座標**: デジタル庁のアドレス・ベース・レジストリで、住居（〇番〇号）・街区・地番・町丁目の順に、求められる最も細かい位置を使います。町丁目までしか求められない施設は、厚生労働省「医療情報ネット」のオープンデータに同じ施設があればその座標を使います。精度や出所は施設ごとに `location.level` で返します（方法と実測の精度は [DATABASE.md](DATABASE.md) の「ジオコーディング」）。
- **テーブル設計**: 詳細は [DATABASE.md](DATABASE.md) を参照してください。

```
app/Services/Rhb/
├── Download/   局ごとの一覧ページ解析（LinkResolver）と、ファイル展開（BundleExpander）
├── Import/     xlsx の読み取りと、住所・電話番号・指定履歴・診療科目などの項目変換
└── Sync/       施設データの保存・変更検出と、廃止検知
```

### 取り扱う範囲の設定

全国のデータが必要ない場合は、`.env` で取り扱う都道府県とカテゴリを絞れます。範囲外のデータは取得も保存もしません。都道府県はどの組み合わせでも指定できます（離れた県どうしでも可）。

```dotenv
RHB_PREFECTURES=02,39      # 都道府県コード（カンマ区切り、空なら全国）
RHB_CATEGORIES=pharmacy    # medical / dental / pharmacy（カンマ区切り、空なら全カテゴリ）
```

- 見に行く地方厚生局は、指定した都道府県から自動で決まります（例: `02,39` なら東北と四国だけ）。
- 多くの局は全県分を1つのファイルにまとめて公開しているため、対象の局のファイルは取得しますが、取り込むのは指定した県だけです。九州厚生局は県ごとのファイルなので、範囲外の県は取得もしません。
- カテゴリは九州以外ではファイルの単位なので、範囲外のカテゴリは取得しません。
- `rhb:status`（監視）、`/api/v1/options` の選択肢、出典表示（`meta.attribution.sources`）も、範囲内のものだけになります。
- あとから県を追加した場合、その県の既存の施設は「新規」ではなく「初回取込」として記録されます。
- 範囲を狭めても、取込済みのデータは自動では削除されません（更新は止まります）。削除するには `rhb:prune` を実行します。

```bash
vendor/bin/sail artisan rhb:prune --dry-run   # 範囲外の施設・変更履歴・ダウンロードの件数を表示
vendor/bin/sail artisan rhb:prune             # 確認のうえ削除（--force で確認を省略）
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

### 定期実行とキューワーカー

毎日のダウンロード（05:00）と取込（05:30）などの定期実行をローカルでも動かす場合は、`.env` に次の1行を追加して起動し直してください。定期実行用の `scheduler` と、取込ジョブを処理する `queue` のコンテナが一緒に起動し、Docker を再起動しても自動で立ち上がります。

```bash
# .env
COMPOSE_PROFILES=background
```

```bash
vendor/bin/sail up -d
vendor/bin/sail logs -f scheduler queue   # 動作の確認
```

既定では起動しません（clone しただけの環境で、各局のサイトへの定期アクセスが始まらないようにするため）。止めるときは `.env` の行を消して `vendor/bin/sail stop scheduler queue` を実行してください。

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

市区町村（`municipality`）と座標（`location`）は、デジタル庁の[アドレス・ベース・レジストリ](https://catalog.registries.digital.go.jp/rc/dataset/)（CC BY 4.0）を加工して作成しています。出典は `meta.attribution.address_source` にあります。町丁目までしか求められない施設の座標と、施設の診療時間・休診日は、厚生労働省の[医療情報ネットのオープンデータ](https://www.mhlw.go.jp/stf/seisakunitsuite/bunya/kenkou_iryou/iryou/newpage_43373.html)（PDL1.0）を加工して作成しています（`meta.attribution.medical_info_net_source`）。集計APIの市区町村の人口（`population`）は、総務省の[住民基本台帳に基づく人口、人口動態及び世帯数](https://www.soumu.go.jp/main_sosiki/jichi_gyousei/daityo/jinkou_jinkoudoutai-setaisuu.html)（政府標準利用規約、CC BY 4.0 互換）を加工して作成しています（`meta.attribution.population_source`）。

- **免責**: データの正確性・完全性は保証しません。最新かつ正確な情報は、各地方厚生局の公表資料を確認してください。
- **個人名は提供しません**: 元データの開設者名・管理者名は個人名を含むため、APIでは返しません。

詳細は [DATABASE.md の「データの出典・利用条件」](DATABASE.md#データの出典利用条件) を参照してください。

## ライセンス

ソースコードは [MIT License](LICENSE) で公開しています。商用・非商用を問わず、自由に利用・改変・再配布できます。

このリポジトリをもとに開発される場合は、README などに「[Tomonori Yoshida のリポジトリ](https://github.com/TomonoriYoshida/medical-facility-master-api-laravel)をベースに開発」とクレジットを記載していただけると嬉しいです（ライセンス上の義務ではなく、お願いです）。

API が返すデータの利用条件は、ソースコードのライセンスとは別です。前述の「データの出典・利用条件」を参照してください。

### サードパーティのデータ

市区町村の一覧 [database/seeders/data/municipalities.csv](database/seeders/data/municipalities.csv) は、デジタル庁のアドレス・ベース・レジストリ「市区町村マスター」（CC BY 4.0）から廃止済みを除いて作成したものです。

異体字の対応表 [database/seeders/data/itaiji-mapping.csv](database/seeders/data/itaiji-mapping.csv) は、Unicode コンソーシアムの [Unihan データベース](https://www.unicode.org/charts/unihan.html)（`kJapaneseOldVariant` / `kJapaneseNewVariant`）から抽出したデータを含みます。このデータは [Unicode License v3](database/seeders/data/LICENSE-Unicode.txt) に従います。
