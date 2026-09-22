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
| `name_normalized` | string | ✓ | `name`をNFKC正規化＋異体字統合（`kanji_variants`参照）した検索用カラム。`MedicalFacilityObserver`が保存時に自動計算するため`#[Fillable]`には含まれない |
| `name_kana` | string | ✓ | 正式名称（フリガナ） |
| `short_name` | string | ✓ | 略称 |
| `short_name_normalized` | string | ✓ | `short_name`の正規化版（`name_normalized`と同じ仕組み。`short_name`が`null`の場合はこちらも`null`のまま） |
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

インデックス: `institution_type`、`prefecture_code`、`name_normalized`、`short_name_normalized`（`source_id`はuniqueインデックス）。`name_normalized`/`short_name_normalized`への単一カラムインデックスは前方一致（`LIKE 'foo%'`）向けであり、部分一致検索（`LIKE '%foo%'`）には効かない。

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

## `kanji_variants`

漢字の異体字（例: 髙⇄高）を検索用に統合するためのマスタテーブル。`name_normalized`/`short_name_normalized`の計算に使う。

| カラム | 型 | NULL | 説明 |
|---|---|---|---|
| `id` | bigint (PK) | - | 内部主キー |
| `variant_character` | string(8), unique | - | 異体字（1文字） |
| `canonical_character` | string(8) | - | 正規化後の標準字体（1文字） |
| `source` | string | - | `kJapaneseNewVariant` / `kJapaneseOldVariant`（Unicodeコンソーシアムの Unihanデータベース由来）/ `manual`（個別に検証して手動追加したもの） |
| `created_at` / `updated_at` | timestamp | - | |

368件（自動抽出364件＋手動補完4件）を`KanjiVariantSeeder`でシードする。データの選定経緯:

- 当初検討した「住基統一文字コード 正字対応表」（政府PDF）は、私用領域(PUA)の古いレガシーコードを実在のUnicode文字に対応付けるための表であり、「髙⇄高」のような既存のUnicode文字同士の異体字統合には使えないと判明したため採用しなかった
- Unicodeコンソーシアム公式のUnihanデータベース（`Unihan_Variants.txt`）の`kJapaneseOldVariant`/`kJapaneseNewVariant`フィールド（日本語の旧字体→新字体に特化、364件）を自動抽出のコアとして採用
- より広い`kSemanticVariant`フィールドは、多段連鎖させると本来別字として扱うべき文字まで誤って統合してしまうリスクが判明したため自動抽出には使わず、個別に検証した4件（髙→高、﨑→崎、嵜→崎、邉→辺）のみ手動で補完した

### 正規化ロジック（`App\Services\Text\ItaijiNormalizer`）

1. `Normalizer::normalize($value, Normalizer::FORM_KC)`（PHPの`intl`拡張）でNFKC正規化（全角英数字・全角スペース等を半角に統一）
2. `kanji_variants`のマッピングで異体字を標準字体に置換（`strtr()`、`once()`でインスタンス単位にメモ化）

`MedicalFacility`保存時（`saving`イベント）に`MedicalFacilityObserver`が`name`/`short_name`の変更を検知して自動的に`name_normalized`/`short_name_normalized`を計算する。`DatabaseSeeder`は`WithoutModelEvents`を使用しているため、将来`MedicalFacility`を一括生成するインポート処理で同様の設定を使う場合は、正規化カラムが自動計算されない点に注意（明示的に`ItaijiNormalizer`を呼び出す必要がある）。

異体字変換を別APIとして切り出すことも検討したが、現時点では利用者がこのアプリ1つのみでありYAGNIと判断し、アプリ内に閉じて実装した。

## 設計上の注意点

- このデータセットには電話番号・郵便番号のカラムが存在しない（MHLWオープンデータの仕様上未提供）。
- 開設者・運営法人（医療法人など）の情報もこのデータセットには含まれておらず、今回はスコープ外とした。必要になった場合は`medical_organizations`テーブルを新設し`medical_facilities`にnullable FKを追加する形を想定。
- 都道府県コード・市区町村コードはあえて正規化せず、コード文字列のまま保持する方針とした。
- 休診日スケジュール・診療/営業時間は、元データでは「1曜日/1パターン=1カラム」で数十〜100カラム超に及ぶが、本設計ではJSONカラムに正規化して保持している。
- 現時点ではスキーマ（マイグレーション・モデル・ファクトリ）のみを実装済み。実CSVの自動インポート・定期クロール・REST API・認証・テストは今後の別フェーズで対応する。
