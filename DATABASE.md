# データベース設計

医療施設マスタAPIのテーブル構造。地方厚生局（都道府県を8ブロックに分けて管轄する厚生労働省の地方支分部局）が
それぞれ公開する「コード内容別医療機関一覧表」（保険医療機関・保険薬局の指定一覧）の実データ構造に基づいて設計している。

当初は厚生労働省「医療機能情報提供制度」オープンデータCSVを情報源としていたが、電話番号・郵便番号・開設者情報が
欠落しているという構造的制約があったため、より情報量の多い地方厚生局データへ全面的に切り替えた（詳細は本ファイル末尾
「データソースの変遷」参照）。

## ER概要

```
medical_facilities (1) ──< (多) medical_facility_events
```

診療科目は`medical_facilities.department_categories`に大分類タグの配列として直接持たせており、
別テーブルには分離していない（旧`medical_facility_departments`は廃止）。

## `medical_facilities`

施設マスタのコアテーブル。医科（病院・診療所）・歯科診療所・薬局で共通（助産所は保険医療機関制度の対象外のため扱わない）。

| カラム | 型 | NULL | 説明 |
|---|---|---|---|
| `id` | bigint (PK) | - | 内部主キー |
| `facility_code` | string(7) | - | 地方厚生局発行の医療機関コード。区切り文字（カンマ/ハイフン等、局によって表記が異なる）を除去した数字7桁に正規化して保持。再インポート時のupsertキーの一部 |
| `bureau_code` | unsignedTinyInteger | - | `App\Enums\RhbBureau` をcast。どの地方厚生局が発行したコードかを表す。`facility_code`は`(都道府県, institution_type)`の組ごとにしか一意性が保証されない（実データ検証済み：関東信越厚生局の実データで、無関係な施設が異なる県で同一の7桁コードを持つ実例、および同一県内でも医科施設と歯科施設が同一コードを持つ実例の両方を確認）ため、`(bureau_code, prefecture_code, institution_type, facility_code)`の複合キーで一意性を担保している |
| `institution_type` | unsignedTinyInteger | - | `App\Enums\InstitutionType` をcast。1:病院 2:診療所 3:歯科診療所 4:薬局 |
| `status` | unsignedTinyInteger | - | `App\Enums\MedicalFacilityStatus` をcast。1:Active 2:Closed 3:Suspended（休止）。デフォルト1。実データで休止は0.72%出現する実在のステータスで、廃業（Closed）とは意味が異なる（施設情報・診療科目は保持されたまま指定効力のみ停止している状態） |
| `last_seen_rhb_dataset_download_id` | FK → `rhb_dataset_downloads`, nullable | ✓ | `nullOnDelete()`。廃業検知用の監視カラム。インポート処理が施設を作成・更新・再活性化するたびに、その回の`rhb_dataset_downloads.id`を記録する |
| `name` | string | - | 医療機関名称 |
| `name_normalized` | string | ✓ | `name`をNFKC正規化＋異体字統合（`kanji_variants`参照）した検索用カラム。`MedicalFacilityObserver`が保存時に自動計算するため`#[Fillable]`には含まれない |
| `prefecture_code` | string(2) | - | 都道府県コード |
| `postal_code` | string(8) | ✓ | 郵便番号（`〒NNN－NNNN`形式の原本から抽出） |
| `address` | string | - | 所在地 |
| `address_normalized` | string | ✓ | `address`を`App\Services\Text\AddressNormalizer`で正規化した検索用カラム。`MedicalFacilityObserver`が保存時に自動計算するため`#[Fillable]`には含まれない |
| `latitude` | decimal(10,6) | ✓ | 所在地座標（緯度）。地方厚生局データには含まれないため、この経路からのインポートでは常にnull。将来のジオコーディング機能に備えてカラムのみ温存 |
| `longitude` | decimal(10,6) | ✓ | 所在地座標（経度）。同上 |
| `phone_number` | string | ✓ | 電話番号。区切り文字を`0X-XXXX-XXXX`形式のハイフンに正規化して保持（括弧区切り・連続ハイフンのタイプミスのみ補正、桁の欠落など元データから正しい形を機械的に復元できないものはそのまま保持） |
| `founder_name` | string | ✓ | 開設者（法人名＋代表者名等、原本の表記をそのまま保持） |
| `administrator_name` | string | ✓ | 管理者名 |
| `designated_on` | date | ✓ | 指定年月日（最初の指定日） |
| `designation_history` | json, nullable | ✓ | 指定年月日欄に埋め込まれた処理履歴（新規／組織変更／交代等の事由と日付のペアの配列）。**`medical_facility_events`には流し込まない**——events テーブルは「自分（インポーター）が今回の同期で検知した変化」を意味する追記専用ログであり、この履歴はインポート開始以前から存在する情報のため意味が異なる。単なるマップ済み属性として通常の差分検出（`AttributeDiff`）の対象にする |
| `bed_counts` | json, nullable | ✓ | 病床種別（療養／一般／精神等）→ 病床数のラベル付き辞書。薬局は常にnull |
| `department_categories` | json, nullable | ✓ | `App\Enums\DepartmentBaseCategory`値の配列（`AsEnumCollection`キャスト）。医科・歯科のみ、薬局は常に空配列。原本の診療科目欄は「基本診療科名＋自由な修飾語」の組み合わせ命名が医療法施行規則で公式に許容されており事実上自由記述に近いため、修飾語を含む完全一致ではなく「大分類（内科系・外科系など）のどれに該当するか」というマーカーマッチによる粗い分類に留めている（実データ検証で出現件数の96.3%を分類可能と確認済み。完全一致の復元は制度上原理的に不可能） |
| `created_at` / `updated_at` | datetime | - | |

インデックス: `institution_type`、`status`、`prefecture_code`、`name_normalized`。`unique(bureau_code, prefecture_code, institution_type, facility_code)`。複合インデックス`medical_facilities_reconcile_index`（`institution_type`, `prefecture_code`, `status`, `last_seen_rhb_dataset_download_id`）は廃業検知クエリ用——`prefecture_code`を含むのは、1件の`rhb_dataset_downloads`行が複数県をまとめて束ねる局（東北・関東信越等）が存在するため、廃業検知が誤って別県の施設まで対象にしないためのスコープ絞り込み。

## `medical_facility_events`

施設のライフサイクル履歴（開業・廃業・更新）を記録する追記専用のイベントログ。`medical_facilities`は現在状態のみを保持する構造のままにし（upsert前提）、変化そのものはこちらのテーブルに記録する。`rhb_dataset_downloads`と同じ「追記型ログ」の設計思想を踏襲している。

| カラム | 型 | NULL | 説明 |
|---|---|---|---|
| `id` | bigint (PK) | - | 内部主キー |
| `medical_facility_id` | FK → `medical_facilities` | - | `restrictOnDelete()`。監査ログとしての性質上、イベント履歴が残っている施設の物理削除を防ぐため |
| `event_type` | unsignedTinyInteger | - | `App\Enums\MedicalFacilityEventType` をcast。1:Created 2:Removed 3:Updated の3種類のみ（粗い粒度）。「再開」は別種別にせず、「過去に`Removed`イベントがある施設への`Created`」として導出する。休止⇔現存の切り替えも特別扱いせず、通常の`Updated`イベント（`payload`内の`status`変化）として記録する |
| `occurred_on` | date | - | 検出元スナップショットの日付。**インポート実行日時（`now()`）ではなく`rhb_dataset_downloads.published_on`を使うこと** |
| `payload` | json | ✓ | 変更前後の値の差分（`Updated`）や、その時点のスナップショット（`Created`/`Removed`）など、変更内容の詳細 |
| `rhb_dataset_download_id` | FK → `rhb_dataset_downloads`, nullable | ✓ | `nullOnDelete()`。どのダウンロードスナップショットから検出されたイベントかの出典情報 |
| `created_at` / `updated_at` | datetime | - | |

インデックス: `(event_type, occurred_on)`。「2026年1月に新規開業した施設一覧」は次のクエリで取得できる。

```sql
SELECT mf.*
FROM medical_facility_events e
JOIN medical_facilities mf ON mf.id = e.medical_facility_id
WHERE e.event_type = 1 -- Created
  AND e.occurred_on BETWEEN '2026-01-01' AND '2026-01-31'
```

診療科目単位のイベント（旧`department_code`カラム）は、診療科目が独立したレコードではなく施設に紐づく大分類タグの配列になったことに伴い廃止した。診療科目の変化は`department_categories`カラムの差分として、施設単位の`Updated`イベントのpayloadに含まれる。

## `rhb_dataset_downloads`

地方厚生局データのダウンロード履歴を記録する追記専用のログテーブル。`app/Console/Commands/DownloadRhbDatasets.php`（`rhb:download`）が、局ごとのページに掲載された日付付きリンクを前回記録分と比較し、新しいバージョンが見つかった時だけ行を追加する。

| カラム | 型 | NULL | 説明 |
|---|---|---|---|
| `id` | bigint (PK) | - | 内部主キー |
| `bureau_code` | unsignedTinyInteger | - | `App\Enums\RhbBureau` をcast |
| `category` | unsignedTinyInteger | - | `App\Enums\RhbCategory` をcast。1:医科 2:歯科 3:薬局 |
| `prefecture_codes` | json | - | このダウンロード1件が束ねる都道府県コードの一覧（例: 北海道は`["01"]`のみ、東北の1ファイルは6県分を束ねるため6件）。局によってはZIP圧縮された複数ファイル・複数シート1ファイルを1回のダウンロードとして扱うため、県単位でファイルが分かれるとは限らない |
| `filename` | string | - | ダウンロードしたファイル名 |
| `source_url` | string | - | ダウンロード元URL |
| `local_path` | string | - | `Storage::disk('local')`上の保存パス（Git管理外） |
| `published_on` | date | - | ページに記載された公開日 |
| `downloaded_at` | datetime | - | 実際にダウンロードした日時 |
| `created_at` / `updated_at` | datetime | - | |

`unique(bureau_code, category, filename)`により、同一バージョンの重複ダウンロード・重複行を防ぐ。「展開後の県×カテゴリ×ファイル」単位の状態は別テーブルに持たず、インポート実行のたびに`BundleExpander`で決定論的に再導出する（状態がドリフトする余地を増やさないため）。

## `kanji_variants`

漢字の異体字（例: 髙⇄高）を検索用に統合するためのマスタテーブル。`name_normalized`の計算に使う。データソースの変更とは無関係に維持している基盤テーブル。

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

`MedicalFacility`保存時（`saving`イベント）に`MedicalFacilityObserver`が`name`の変更を検知して自動的に`name_normalized`を計算する。`DatabaseSeeder`は`WithoutModelEvents`を使用しているため、将来`MedicalFacility`を一括生成するインポート処理で同様の設定を使う場合は、正規化カラムが自動計算されない点に注意（明示的に`ItaijiNormalizer`を呼び出す必要がある）。

異体字変換を別APIとして切り出すことも検討したが、現時点では利用者がこのアプリ1つのみでありYAGNIと判断し、アプリ内に閉じて実装した。

### 住所正規化ロジック（`App\Services\Text\AddressNormalizer`）

`ItaijiNormalizer`を合成し（NFKCで全角数字・全角ハイフンを半角化）、その後に住所特有のルールを1点追加する: 実データで番地区切りにカタカナ長音記号「ー」が誤用されているケース（例: `丸塚町１５７ー１`）を、数字に前後を挟まれている場合のみハイフンへ変換する。`ガーデンハウス`のような建物名内の正当な長音記号はカナに前後を挟まれるため対象外——機械的に判別できるのはこの文脈のみと実データで確認済み。`name_normalized`と同じく`MedicalFacilityObserver`が`address`の変更を検知して自動計算する。

## 設計上の注意点

- 地方厚生局データには開設者・管理者の氏名や指定年月日は含まれるが、法人番号のような構造化された運営法人IDは含まれない。`founder_name`は原本の表記（法人名＋代表者名が1文字列に混在することがある）をそのまま保持している。
- 都道府県コードはあえて正規化せず、コード文字列のまま保持する方針を継続している。
- 診療科目は時間帯付きの構造化データを持たない（旧`医療機能情報提供制度`データにあった診療時間・受付時間の情報は、地方厚生局データには存在しない）。
- `latitude`/`longitude`はこのデータソースからは常にnullになる（座標情報自体が原本に存在しない）。将来ジオコーディングを行う場合のためカラムは残している。

## データソースの変遷

1. **旧: 医療機能情報提供制度CSV（フェーズ1〜4、PR #6〜#10、全て破棄済み）** — 厚生労働省が全国一本で公開するCSVを情報源としていた。診療科目の時間帯情報を含む豊富なデータだったが、電話番号・郵便番号・開設者情報が一切なく、実際の指定コードとしての信頼性も低い（内部発番のオープンデータ用IDで、公式な構造説明が存在しないことをMHLW公式の定義書で確認した）ことが判明し、破棄した。
2. **現行: 地方厚生局の保険医療機関指定一覧（フェーズA以降）** — 8つの地方厚生局がそれぞれ独立して公開する「コード内容別医療機関一覧表」を情報源とする。全国一本のCSVではなく、局ごとに異なるURL構造・ファイル形式（PDF/Excel、ZIP圧縮あり）・レイアウト差異（列ヘッダーなしの印刷帳票形式、1レコードが3〜14行の可変長物理行に渡る）を持つため、`app/Services/Rhb/Download/`配下に局ごとの`BureauLinkResolver`/`BundleExpander`実装を追加していく設計にしている。フェーズA（本フェーズ）は北海道1局のみを対象にアーキテクチャを検証するパイロットで、`app/Services/Rhb/Import/`（DB非依存パース層）・`app/Services/Rhb/Sync/`（差分検出・upsert層、`App\Services\Sync\AttributeDiff`を共通利用）・`app/Jobs/ImportRhbFacilityListJob.php`・`rhb:download`/`rhb:import`コマンドで構成される。フェーズB以降で残り7局のダウンロード層を追加し、8局全ての実装が完了している（パース層・Sync層は最後まで変更不要だった）。`rhb:download`/`rhb:import`は`routes/console.php`で毎日JST 5:00/5:30に定期実行されるようスケジュール済み（`rhb:import`は`Bus::batch()`でキューに投入するだけのため、実際の取り込みには別途永続的なキューワーカー——ローカルでは`composer run dev`が起動する`queue:listen`、本番相当の運用ではSupervisor等で管理する`queue:work`——が稼働している必要がある。スケジューラ自体はワーカーを起動しない）。REST API・認証は引き続き別フェーズで対応する。
3. **「10年スパンの運用に耐えるか」という観点**は継続して重視しており、全テーブルの`created_at`/`updated_at`等はMySQL `DATETIME`型（西暦9999年まで対応、32bit Unix時間に依存する`TIMESTAMP`型の2038年問題を回避）で最初から作成している。

## データの出典・利用条件

地方厚生局の公開データは、各局サイトの利用規約ページ（例: [北海道厚生局 利用規約・リンク・著作権等](https://kouseikyoku.mhlw.go.jp/hokkaido/aboutus/copyright.html)）で確認した通り、「公共データ利用規約（第1.0版）」（PDL1.0）に準拠しており、リンクフリー・二次利用（加工・編集を含む）ともに許可されている（商用利用の制限・再配布禁止の記載なし）。ただし以下2点が利用条件として明記されているため、このデータを画面・API等で利用者に提示する場合は遵守すること。

1. **出典の記載が必須**：例）出典：「（局名の）内の保険医療機関・保険薬局の指定一覧」（○○厚生局）（当該ページのURL）
2. **加工・編集した場合はその旨の記載も必須**：例）「北海道内の保険医療機関・保険薬局の指定一覧」（北海道厚生局）を加工して作成。また、加工後の情報をあたかも国（または府省等）が作成したかのような態様で公表・利用することは禁止されている。

本アプリは取得したデータを構造化・分類（診療科目の大分類化等）した上でデータベースに格納しており、上記の「加工・編集」に該当する。将来的にAPI・画面を公開する際は、レスポンス・フッター等に上記の出典表示を含めること。
