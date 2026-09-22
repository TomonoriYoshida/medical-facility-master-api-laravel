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
| `status` | unsignedTinyInteger | - | `App\Enums\MedicalFacilityStatus` をcast。1:Active 2:Closed。デフォルト1。廃業しても行は物理削除せずこのカラムだけ変える（`medical_facility_events`のFKが指す先を保持するため） |
| `last_seen_mhlw_dataset_download_id` | FK → `mhlw_dataset_downloads`, nullable | ✓ | `nullOnDelete()`。廃業検知用の監視カラム。インポート処理が施設を作成・更新・再活性化するたびに、その回の`mhlw_dataset_downloads.id`を記録する。インポート完了後「対象施設種別でActiveなのに今回のIDが記録されていない」行を検索することで、CSVから消えた（＝廃業した）施設を検出する |
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
| `closure_schedule` | json | - | 休診(業)スケジュール。毎週の曜日別フラグ・第1〜5週パターン・祝日フラグ・その他休診日をまとめて格納（下記参照）。薬局のみ追加キーを持つ |
| `business_hours` | json | ✓ | **助産所・薬局のみ使用**。曜日×複数時間帯の営業時間（下記参照）。助産所は「就業時間帯」、薬局は「開店時間帯」に対応。病院・診療所・歯科診療所は診療科目側で時間を持つためnull |
| `reception_hours` | json | ✓ | **助産所のみ使用**（「外来受付時間帯」に対応）。薬局・病院・診療所・歯科診療所はnull。構造は`business_hours`と同じ |
| `general_beds` | unsignedSmallInteger | ✓ | 一般病床（病院・診療所のみ） |
| `sanatorium_beds` | unsignedSmallInteger | ✓ | 療養病床（病院・診療所のみ） |
| `sanatorium_beds_medical_insurance` | unsignedSmallInteger | ✓ | 療養病床のうち医療保険適用（病院・診療所のみ） |
| `sanatorium_beds_care_insurance` | unsignedSmallInteger | ✓ | 療養病床のうち介護保険適用（病院・診療所のみ） |
| `psychiatric_beds` | unsignedSmallInteger | ✓ | 精神病床（病院のみ） |
| `tuberculosis_beds` | unsignedSmallInteger | ✓ | 結核病床（病院のみ） |
| `infectious_disease_beds` | unsignedSmallInteger | ✓ | 感染症病床（病院のみ） |
| `total_beds` | unsignedSmallInteger | ✓ | 合計病床数（病院・診療所のみ） |
| `created_at` / `updated_at` | datetime | - | |

インデックス: `institution_type`、`status`、`prefecture_code`、`name_normalized`、`short_name_normalized`（`source_id`はuniqueインデックス）。複合インデックス`medical_facilities_reconcile_index`（`institution_type`, `status`, `last_seen_mhlw_dataset_download_id`）は廃業検知クエリ用。`name_normalized`/`short_name_normalized`への単一カラムインデックスは前方一致（`LIKE 'foo%'`）向けであり、部分一致検索（`LIKE '%foo%'`）には効かない。

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
`weekly`/`monthly_pattern`の値は元データと同じ意味（0:休診(業) 1:診療(営業)）。実データでは空文字（未設定）も存在するため、`0`/`1`だけでなく`null`（未設定）も許容する。

**薬局のみ追加キーを持つ**: 薬局の休診スケジュール欄は他4種別（44列: 週7列＋第1〜5週35列＋祝日1列＋その他1列）と異なり53列で、他の種別にはない「営業日」（8列、祝日列を含む）ブロックが先頭にあり、「定期閉店毎週」にも祝日列が含まれる。一方で既存の「祝日」単独フラグも別途存在し、祝日関連のシグナルが2つ並存する形になっている。この2つを推測でマージせず、`open_weekdays`（曜日別の営業日フラグ、祝日列含む）・`weekly_holiday_flag`（定期閉店毎週の祝日列）という薬局専用の追加キーとしてそのまま保持する。意味の統合が必要になった場合はMHLWの正式なレイアウト定義書を確認してから行う。

### `business_hours` / `reception_hours` の構造例（助産所・薬局）

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
| `last_seen_mhlw_dataset_download_id` | FK → `mhlw_dataset_downloads`, nullable | ✓ | `nullOnDelete()`。`medical_facilities`と同じ仕組みの廃業（診療科目廃止）検知用の監視カラム |
| `created_at` / `updated_at` | datetime | - | |

一意制約: `(medical_facility_id, department_code)`。将来のMHLW再インポートで同一施設・同一診療科目の行が重複作成されるのを防ぐ。

### `consultation_hours` / `reception_hours` の構造例

`business_hours`と同じ「曜日ごとに時間帯の配列」形状。実データでは1つの診療科目が複数の時間帯（診療時間帯1〜3、午前/午後など）を持つことが多く（時間帯2は全体の約45%、時間帯3も約2%で使用されており珍しくない）、1日1枠固定の形状では実データの半数近くを欠落させてしまうため、配列形状を採用している。

```json
{
  "mon": [{ "start": "09:00", "end": "12:00" }, { "start": "14:00", "end": "17:30" }],
  "tue": [{ "start": "09:00", "end": "12:00" }],
  "wed": [],
  "thu": [{ "start": "09:00", "end": "12:00" }, { "start": "14:00", "end": "17:30" }],
  "fri": [{ "start": "09:00", "end": "12:00" }],
  "sat": [],
  "sun": [],
  "holiday": []
}
```
空配列はその曜日/祝日は診療(受付)なしを表す。

## `medical_facility_events`

施設のライフサイクル履歴（開業・廃業・更新）を記録する追記専用のイベントログ。`medical_facilities`は現在状態のみを保持する構造のままにし（upsert前提）、変化そのものはこちらのテーブルに記録する。`mhlw_dataset_downloads`と同じ「追記型ログ」の設計思想を踏襲している。

| カラム | 型 | NULL | 説明 |
|---|---|---|---|
| `id` | bigint (PK) | - | 内部主キー |
| `medical_facility_id` | FK → `medical_facilities` | - | `restrictOnDelete()`。監査ログとしての性質上、イベント履歴が残っている施設の物理削除を防ぐため（`cascadeOnDelete()`にすると誤削除で履歴ごと消えてしまう） |
| `department_code` | string | ✓ | `null`＝施設単位のイベント、値あり＝その施設の特定の診療科目に関するイベント。診療科目の行自体は廃止時に物理削除してよい（`payload`に科目名等を残せば追跡できる） |
| `event_type` | unsignedTinyInteger | - | `App\Enums\MedicalFacilityEventType` をcast。1:Created 2:Removed 3:Updated の3種類のみ（粗い粒度）。「再開」は別種別にせず、「過去に`Removed`イベントがある施設への`Created`」として導出する |
| `occurred_on` | date | - | 検出元スナップショットの日付。**インポート実行日時（`now()`）ではなく`mhlw_dataset_downloads.published_on`を使うこと** |
| `payload` | json | ✓ | 変更前後の値の差分（`Updated`）や、その時点のスナップショット（`Created`/`Removed`）など、変更内容の詳細 |
| `mhlw_dataset_download_id` | FK → `mhlw_dataset_downloads`, nullable | ✓ | `nullOnDelete()`。どのダウンロードスナップショットから検出されたイベントかの出典情報 |
| `created_at` / `updated_at` | datetime | - | |

インデックス: `(event_type, department_code, occurred_on)`（等値/IS NULL条件を先、範囲条件を最後に置く定石通りの並び）。「2026年1月に新規開業した施設一覧」は次のクエリで取得できる。

```sql
SELECT mf.*
FROM medical_facility_events e
JOIN medical_facilities mf ON mf.id = e.medical_facility_id
WHERE e.event_type = 1 -- Created
  AND e.department_code IS NULL
  AND e.occurred_on BETWEEN '2026-01-01' AND '2026-01-31'
```

**重複イベントの冪等性について**: `department_code`がnullableなため、DB側のUNIQUE制約では施設単位イベント（`department_code IS NULL`）の重複を防げない（MySQLはUNIQUE制約内で複数のNULLを別物として扱う）。同じ変化を二重にイベント登録しないようにする重複排除は、将来のインポートロジック側の責務とする（今回はスキーマのみ）。

## `mhlw_dataset_downloads`

MHLWオープンデータのダウンロード履歴を記録する追記専用のログテーブル。`app/Console/Commands/DownloadMhlwDatasets.php`（`mhlw:download`）が、ファイル名に埋め込まれた日付を前回記録分と比較し、新しいバージョンが見つかった時だけ行を追加する。

| カラム | 型 | NULL | 説明 |
|---|---|---|---|
| `id` | bigint (PK) | - | 内部主キー |
| `dataset_key` | string | - | データセットの識別子（例: `hospital_facility`）。`config/mhlw.php`のキーと一致 |
| `filename` | string | - | ダウンロードしたファイル名（例: `01-1_hospital_facility_info_20260601.csv.zip`） |
| `published_on` | date | - | ファイル名から抽出した公開日 |
| `source_url` | string | - | ダウンロード元URL |
| `local_path` | string | - | `Storage::disk('local')`上の保存パス（`storage/app/private/mhlw/...`、Git管理外） |
| `downloaded_at` | datetime | - | 実際にダウンロードした日時 |
| `created_at` / `updated_at` | datetime | - | |

`unique(['dataset_key', 'filename'])`により、同一バージョンの重複ダウンロード・重複行を防ぐ。

## `kanji_variants`

漢字の異体字（例: 髙⇄高）を検索用に統合するためのマスタテーブル。`name_normalized`/`short_name_normalized`の計算に使う。

| カラム | 型 | NULL | 説明 |
|---|---|---|---|
| `id` | bigint (PK) | - | 内部主キー |
| `variant_character` | string(8), unique | - | 異体字（1文字） |
| `canonical_character` | string(8) | - | 正規化後の標準字体（1文字） |
| `source` | string | - | `kJapaneseNewVariant` / `kJapaneseOldVariant`（Unicodeコンソーシアムの Unihanデータベース由来）/ `manual`（個別に検証して手動追加したもの） |
| `created_at` / `updated_at` | datetime | - | |

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
- 実CSVインポート機能は複数フェーズに分けて実装し、フェーズ1〜4すべて完了した。フェーズ1（スキーマ修正・廃業検知用監視カラムの追加）・フェーズ2（CSV→構造化データのパース層、`app/Services/Mhlw/Import/`配下）・フェーズ3（差分検出・upsert層、`app/Services/Mhlw/Sync/`配下）・フェーズ4（Job・Queue・`mhlw:import`コマンド）。フェーズ4では`config/mhlw.php`の各データセットに`institution_type`/`role`メタデータを追加し、`ImportFacilityDatasetJob`/`ImportSpecialityDatasetJob`（`app/Jobs/`）を`Bus::batch()`でまとめて非同期キューへdispatchする。同一施設種別内では施設データセット→診療科目データセットの順で処理されるようチェーン化しており（`DepartmentClosureReconciler`が施設側の廃業判定コミット後の実行を要求するため）、`mhlw:import`コマンドは実行してキュー投入するのみで、実際の処理は別途起動しているワーカー（`queue:work`または`composer run dev`）が行う。定期クロール・REST API・認証は別フェーズで対応する。
- 「10年スパンの運用に耐えるか」という観点で見直しを行い、全テーブルの`created_at`/`updated_at`等を当初のMySQL `TIMESTAMP`型（2038年1月19日で範囲外になる32bit Unix時間）から`DATETIME`型（西暦9999年まで対応）に変更した。`occurred_on`/`published_on`は元々`date`型のため対象外。合わせて`medical_facility_departments`に`(medical_facility_id, department_code)`の一意制約を追加し、将来の再インポートで重複行が蓄積しないようにした。
