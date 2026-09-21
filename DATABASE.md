# データベース設計

医療施設マスタAPIのテーブル構造。厚生労働省「医療機能情報提供制度」オープンデータ
(https://www.mhlw.go.jp/stf/seisakunitsuite/bunya/kenkou_iryou/iryou/newpage_43373.html)
の実データ構造に基づいて設計している。

## ER概要

```
medical_facilities (1) ──< (多) medical_facility_departments
```

`medical_facility_departments` は病院・診療所・歯科診療所のみが行を持つ。助産所・薬局は
診療科目という概念を持たず、`medical_facilities.business_hours` に直接営業時間を持つ。

## `medical_facilities`

施設マスタのコアテーブル。全5施設種別（病院／診療所／歯科診療所／助産所／薬局）で共通。

| カラム | 型 | NULL | 説明 |
|---|---|---|---|
| `id` | bigint (PK) | - | 内部主キー |
| `source_id` | string, unique | - | MHLW側の元`ID`（13桁、先頭ゼロ保持のため文字列）。再インポート時のupsertキー |
| `institution_type` | unsignedTinyInteger | - | `App\Enums\InstitutionType` をcast。1:病院 2:診療所 3:歯科診療所 4:助産所 5:薬局 |
| `name` | string | - | 正式名称／名称 |
| `name_kana` | string | ✓ | 正式名称（フリガナ） |
| `short_name` | string | ✓ | 略称 |
| `short_name_kana` | string | ✓ | 略称（フリガナ） |
| `name_en` | string | ✓ | 英語表記（ローマ字表記） |
| `prefecture_code` | string(2) | - | 都道府県コード（生のコード文字列のまま保持） |
| `city_code` | string(3) | - | 市区町村コード（生のコード文字列のまま保持） |
| `address` | string | - | 所在地 |
| `latitude` | decimal(10,6) | ✓ | 所在地座標（緯度） |
| `longitude` | decimal(10,6) | ✓ | 所在地座標（経度） |
| `website_url` | string | ✓ | 案内用ホームページアドレス |
| `closure_schedule` | json | - | 休診(業)スケジュール。毎週の曜日別フラグ・第1〜5週パターン・祝日フラグ・その他休診日をまとめて格納（下記参照） |
| `business_hours` | json | ✓ | **助産所・薬局のみ使用**。曜日×複数時間帯の営業時間（下記参照）。病院・診療所・歯科診療所は診療科目側で時間を持つためnull |
| `general_beds` | unsignedSmallInteger | ✓ | 一般病床（病院・診療所のみ） |
| `sanatorium_beds` | unsignedSmallInteger | ✓ | 療養病床（病院・診療所のみ） |
| `sanatorium_beds_medical_insurance` | unsignedSmallInteger | ✓ | 療養病床のうち医療保険適用（病院・診療所のみ） |
| `sanatorium_beds_care_insurance` | unsignedSmallInteger | ✓ | 療養病床のうち介護保険適用（病院・診療所のみ） |
| `psychiatric_beds` | unsignedSmallInteger | ✓ | 精神病床（病院のみ） |
| `tuberculosis_beds` | unsignedSmallInteger | ✓ | 結核病床（病院のみ） |
| `infectious_disease_beds` | unsignedSmallInteger | ✓ | 感染症病床（病院のみ） |
| `total_beds` | unsignedSmallInteger | ✓ | 合計病床数（病院・診療所のみ） |
| `created_at` / `updated_at` | timestamp | - | |

インデックス: `institution_type`、`prefecture_code`（`source_id`はuniqueインデックス）

### `closure_schedule` の構造例

```json
{
  "weekly": { "mon": 1, "tue": 1, "wed": 1, "thu": 1, "fri": 1, "sat": 0, "sun": 0 },
  "monthly_pattern": {
    "1": { "mon": 1, "tue": 1, "wed": 1, "thu": 1, "fri": 1, "sat": 1, "sun": 0 },
    "2": { "...": "..." }
  },
  "holiday": 0,
  "other_closed_dates": ["01-01", "01-02", "01-03", "12-29", "12-30", "12-31"]
}
```
`weekly`/`monthly_pattern`の値は元データと同じ意味（0:休診(業) 1:診療(営業)）。

### `business_hours` の構造例（助産所・薬局）

```json
{
  "mon": [{ "start": "09:00", "end": "13:00" }, { "start": "14:00", "end": "18:00" }],
  "tue": [{ "start": "09:00", "end": "13:00" }],
  "...": "...",
  "sun": [],
  "holiday": []
}
```

## `medical_facility_departments`

診療科目テーブル。病院・診療所・歯科診療所のみ行を持つ（助産所・薬局には対応する診療科目がない）。

| カラム | 型 | NULL | 説明 |
|---|---|---|---|
| `id` | bigint (PK) | - | 内部主キー |
| `medical_facility_id` | foreignId | - | `medical_facilities.id` へのFK。`cascadeOnDelete` |
| `department_code` | string | - | 診療科目コード |
| `department_name` | string | - | 診療科目名（例: 内科） |
| `consultation_hours` | json | - | 曜日別・診療開始/終了時間（下記参照） |
| `reception_hours` | json | - | 曜日別・外来受付開始/終了時間（構造は`consultation_hours`と同じ） |
| `created_at` / `updated_at` | timestamp | - | |

### `consultation_hours` / `reception_hours` の構造例

```json
{
  "mon": { "start": "09:00", "end": "17:30" },
  "tue": { "start": "09:00", "end": "17:30" },
  "wed": null,
  "thu": { "start": "09:00", "end": "17:30" },
  "fri": { "start": "09:00", "end": "17:30" },
  "sat": null,
  "sun": null,
  "holiday": null
}
```
`null`はその曜日/祝日は診療(受付)なしを表す。

## 設計上の注意点

- このデータセットには電話番号・郵便番号のカラムが存在しない（MHLWオープンデータの仕様上未提供）。
- 開設者・運営法人（医療法人など）の情報もこのデータセットには含まれておらず、今回はスコープ外とした。必要になった場合は`medical_organizations`テーブルを新設し`medical_facilities`にnullable FKを追加する形を想定。
- 都道府県コード・市区町村コードはあえて正規化せず、コード文字列のまま保持する方針とした。
- 休診日スケジュール・診療/営業時間は、元データでは「1曜日/1パターン=1カラム」で数十〜100カラム超に及ぶが、本設計ではJSONカラムに正規化して保持している。
- 現時点ではスキーマ（マイグレーション・モデル・ファクトリ）のみを実装済み。実CSVの自動インポート・定期クロール・REST API・認証・テストは今後の別フェーズで対応する。
