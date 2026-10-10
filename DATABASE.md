# データベース設計

医療施設マスタAPIのテーブル構造。地方厚生局（都道府県を8ブロックに分けて管轄する厚生労働省の地方支分部局）が
それぞれ公開する「コード内容別医療機関一覧表」（保険医療機関・保険薬局の指定一覧）の実データ構造に基づいて設計している。

当初は厚生労働省「医療機能情報提供制度」オープンデータCSVを情報源としていたが、電話番号・郵便番号・開設者情報が
欠落しているという構造的制約があったため、より情報量の多い地方厚生局データへ全面的に切り替えた（詳細は本ファイル末尾
「データソースの変遷」参照）。

スキーマの定義は`database/schema/mysql-schema.sql`にある。データソース切り替え前（MHLW時代）のテーブル作成・削除を含む
初期のマイグレーション26本を、本番リリース前に`schema:dump --prune`で1ファイルに統合した。以降のスキーマ変更は通常どおり
`database/migrations/`にマイグレーションを追加する（`migrate`はダンプを読み込んだ後に、それらを実行する）。

## ER概要

```
medical_facilities (1) ──< (多) medical_facility_events
medical_info_net_locations (1) ── (0..1) medical_info_net_schedules   ※ source_id で対応。施設とは名称・所在地で照合
national_land_medical_locations                                       ※ 施設とは名称・所在地で照合（座標の補完だけ）
```

診療科目は`medical_facilities.department_categories`に大分類タグの配列として直接持たせており、
別テーブルには分離していない（旧`medical_facility_departments`は廃止）。

## `medical_facilities`

施設マスタのコアテーブル。医科（病院・診療所）・歯科診療所・薬局で共通（助産所は保険医療機関制度の対象外のため扱わない）。

| カラム | 型 | NULL | 説明 |
|---|---|---|---|
| `id` | bigint (PK) | - | 内部主キー |
| `facility_code` | string(7) | - | 地方厚生局発行の医療機関コード。区切り文字（カンマ/ハイフン等、局によって表記が異なる）を除去した数字7桁に正規化して保持。再インポート時のupsertキーの一部 |
| `medical_institution_code` | char(10)（STORED 生成列） | - | 全国で一意な10桁の医療機関コード。`prefecture_code`（2桁）＋点数表番号（1桁。病院・診療所は医科の1、歯科診療所は3、薬局は4）＋`facility_code`（7桁）を MySQL が計算して保存する。レセプトなどで使われる番号で、組み込む側のシステムとの照合に使う。unique |
| `bureau_code` | unsignedTinyInteger | - | `App\Enums\RhbBureau` をcast。どの地方厚生局が発行したコードかを表す。`facility_code`は`(都道府県, institution_type)`の組ごとにしか一意性が保証されない（実データ検証済み：関東信越厚生局の実データで、無関係な施設が異なる県で同一の7桁コードを持つ実例、および同一県内でも医科施設と歯科施設が同一コードを持つ実例の両方を確認）ため、`(bureau_code, prefecture_code, institution_type, facility_code)`の複合キーで一意性を担保している |
| `institution_type` | unsignedTinyInteger | - | `App\Enums\InstitutionType` をcast。1:病院 2:診療所 3:歯科診療所 4:薬局 |
| `status` | unsignedTinyInteger | - | `App\Enums\MedicalFacilityStatus` をcast。1:Active 2:Closed 3:Suspended（休止）。デフォルト1。実データで休止は0.72%出現する実在のステータスで、廃業（Closed）とは意味が異なる（施設情報・診療科目は保持されたまま指定効力のみ停止している状態） |
| `last_seen_rhb_dataset_download_id` | FK → `rhb_dataset_downloads`, nullable | ✓ | `nullOnDelete()`。廃業検知用の監視カラム。インポート処理が施設を作成・更新・再活性化するたびに、その回の`rhb_dataset_downloads.id`を記録する |
| `name` | string | - | 医療機関名称。元データの外字（Unicode の私用領域。「𠮷」「一点しんにょうの辻」など）は、取り込み時に標準の字へ置き換える（`App\Services\Rhb\Import\PrivateUseCharacters`。全セルが対象）。字を特定できない外字は「〓」にする |
| `name_normalized` | string | ✓ | `name`をNFKC正規化＋異体字統合（`kanji_variants`参照）した検索用カラム。`MedicalFacilityObserver`が保存時に自動計算するため`#[Fillable]`には含まれない |
| `prefecture_code` | string(2) | - | 都道府県コード |
| `municipality_code` | char(5) | ✓ | 市区町村コード（全国地方公共団体コード5桁）。`address`の先頭の市区町村名を`App\Services\Address\MunicipalityResolver`が`municipalities`と最長一致で判定し、`MedicalFacilityObserver`が都道府県・住所の変更時に自動計算する（`#[Fillable]`には含まれない）。判定できない住所はnull（全国約22万件中21件、旧字体・誤記の住所）。既存データへの付与・再計算は`facilities:assign-municipalities`（`updated_at`は動かさない）。インデックスあり |
| `postal_code` | string(8) | ✓ | 郵便番号（`〒NNN－NNNN`形式の原本から抽出） |
| `address` | string | - | 所在地 |
| `address_normalized` | string | ✓ | `address`を`App\Services\Text\AddressNormalizer`で正規化した検索用カラム。`MedicalFacilityObserver`が保存時に自動計算するため`#[Fillable]`には含まれない |
| `latitude` | decimal(10,6) | ✓ | 所在地座標（緯度）。地方厚生局データには含まれないため、`facilities:geocode`が住所からアドレス・ベース・レジストリで求める（下記「ジオコーディング」）。求められなかった施設・未処理の施設はnull。住所が変わると`MedicalFacilityObserver`がnullに戻す |
| `longitude` | decimal(10,6) | ✓ | 所在地座標（経度）。同上。`(latitude, longitude)`の複合インデックスで近隣検索の範囲を絞る |
| `geocode_level` | unsignedTinyInteger | ✓ | `App\Enums\GeocodeLevel` をcast。座標の精度。1:住居（〇番〇号） 2:街区（〇番） 3:地番（〇番地〇） 4:地番（枝番なし。同じ地番の別の枝番の座標） 5:町丁目（代表点） 6:医療情報ネット（厚生労働省の座標。下記「ジオコーディング」の5） 7:国土数値情報（国土交通省「国土数値情報（医療機関）」の位置。同じく6）。座標がない施設はnull |
| `geocoded_address` | string | ✓ | 座標を求めたときの`address`。`address`と異なる施設（新規・移転）だけを`facilities:geocode`が処理する。座標を求められなかった施設にも入れ、毎日の再試行を防ぐ（`--all`で再試行） |
| `medical_info_net_id` | string(20) | ✓ | 照合できた医療情報ネットの施設ID（`medical_info_net_locations.source_id`）。`facilities:assign-opening-hours`が毎朝付け直す（`updated_at`は動かさない。APIでは返さず、診療時間のAPIと`open_at`の判定に使う）。照合できない施設はnull |
| `phone_number` | string | ✓ | 電話番号。区切り文字を`0X-XXXX-XXXX`形式のハイフンに正規化して保持（括弧区切り・連続ハイフンのタイプミスのみ補正、桁の欠落など元データから正しい形を機械的に復元できないものはそのまま保持） |
| `founder_name` | string | ✓ | 開設者（法人名＋代表者名等、原本の表記をそのまま保持）。個人名を含むことが多いため、**APIでは返さない** |
| `administrator_name` | string | ✓ | 管理者名（常に個人名）。**APIでは返さない** |
| `designated_on` | date | ✓ | 指定年月日（最初の指定日） |
| `designation_history` | json, nullable | ✓ | 指定年月日欄に埋め込まれた履歴（`{reason, date}` の配列。`reason` は新規／組織変更／交代等の登録理由、`date` は現在の指定期間の開始日と見られる）。約1割の施設は登録理由の記載がなく、`reason` が null になる。**`medical_facility_events`には流し込まない**——events テーブルは「自分（インポーター）が今回の同期で検知した変化」を意味する追記専用ログであり、この履歴はインポート開始以前から存在する情報のため意味が異なる。単なるマップ済み属性として通常の差分検出（`AttributeDiff`）の対象にする |
| `bed_counts` | json, nullable | ✓ | 病床種別（療養／一般／精神等）→ 病床数のラベル付き辞書。元データが病棟ごとに分けて載せている同じ種別の病床は合計する。薬局は常にnull |
| `department_categories` | json, nullable | ✓ | `App\Enums\DepartmentBaseCategory`値の配列（`AsEnumCollection`キャスト）。医科・歯科のみ、薬局は常に空配列。原本の診療科目欄は「基本診療科名＋自由な修飾語」の組み合わせ命名が医療法施行規則で公式に許容されており事実上自由記述に近いため、修飾語を含む完全一致ではなく「大分類（内科系・外科系など）のどれに該当するか」というマーカーマッチによる粗い分類に留めている（実データ検証で出現件数の96.3%を分類可能と確認済み。完全一致の復元は制度上原理的に不可能）。歯科の一覧の施設は、歯科の指定で標榜できるのが歯科系（歯科・小児歯科・矯正歯科・歯科口腔外科）だけのため、診療科目欄があれば常に`歯科`のみとする（略記「小歯」などを1文字の「小」→小児科のように誤分類しないため） |
| `created_at` / `updated_at` | datetime | - | `updated_at`は施設データ（マップ済み属性）が実際に変わった時と、`facilities:geocode`で座標（`latitude`/`longitude`/`geocode_level`）が変わった時だけ更新される。座標を含めるのは、差分同期（`updated_since`）の利用者と一括ダウンロード（`rhb:export`）が座標の追加・変更を拾えるようにするため。取込のたびに行う`last_seen_rhb_dataset_download_id`の更新や、`facilities:renormalize`・`facilities:assign-municipalities`による派生カラムの再計算では変わらない（APIでも「施設情報の最終更新日時」として返しているため） |

インデックス: `institution_type`、`status`、`prefecture_code`、`name_normalized`、`address_normalized`、`(name_normalized, address_normalized)`（一覧APIの`q`の件数取得用。`LIKE '%語%'`はどのインデックスでも探索できず全行を調べるが、2列だけのこのインデックスをテーブルの代わりに読むため約3割速い）、`designated_on`（一覧APIの指定年月日による絞り込み・並び替え用）。`unique(bureau_code, prefecture_code, institution_type, facility_code)`、`unique(medical_institution_code)`。複合インデックス`medical_facilities_reconcile_index`（`institution_type`, `prefecture_code`, `status`, `last_seen_rhb_dataset_download_id`）は廃業検知クエリ用——`prefecture_code`を含むのは、1件の`rhb_dataset_downloads`行が複数県をまとめて束ねる局（東北・関東信越等）が存在するため、廃業検知が誤って別県の施設まで対象にしないためのスコープ絞り込み。

## `medical_facility_events`

施設のライフサイクル履歴（開業・廃業・更新）を記録する追記専用のイベントログ。`medical_facilities`は現在状態のみを保持する構造のままにし（upsert前提）、変化そのものはこちらのテーブルに記録する。`rhb_dataset_downloads`と同じ「追記型ログ」の設計思想を踏襲している。

| カラム | 型 | NULL | 説明 |
|---|---|---|---|
| `id` | bigint (PK) | - | 内部主キー |
| `medical_facility_id` | FK → `medical_facilities` | - | `restrictOnDelete()`。監査ログとしての性質上、イベント履歴が残っている施設の物理削除を防ぐため |
| `event_type` | unsignedTinyInteger | - | `App\Enums\MedicalFacilityEventType` をcast。1:Created 2:Removed 3:Updated の3種類のみ（粗い粒度）。「再開」は別種別にせず、「過去に`Removed`イベントがある施設への`Created`」として導出する。休止⇔現存の切り替えも特別扱いせず、通常の`Updated`イベント（`payload`内の`status`変化）として記録する |
| `origin` | unsignedTinyInteger | - | `App\Enums\MedicalFacilityEventOrigin` をcast。記録された理由。1:Baseline（初回取込。その県・カテゴリの施設がまだ1件もない状態での取込。運用開始時と、あとから `RHB_PREFECTURES` に県を追加したとき。全施設が一度に Created になるだけで、開業ではない） 2:Detected（検知。公開データ間の実際の変化） 3:Reprocessed（再処理。すでに取り込んだ公開データを取り込み直したときの記録。元データは同じなので、差分はパーサー・正規化処理の変更によるもの。変更・再開は、その施設を前回見た公開データと公開日が同じかで施設ごとに判定し、新規は `rhb:import --force` で取込済みのデータを取り込み直したときに付く）。APIで「実際の変化」として扱うのは Detected のみ |
| `occurred_on` | date | - | 検出元スナップショットの日付。**インポート実行日時（`now()`）ではなく`rhb_dataset_downloads.published_on`を使うこと** |
| `payload` | json | ✓ | 変更前後の値の差分（`Updated`）や、その時点のスナップショット（`Created`/`Removed`）など、変更内容の詳細 |
| `rhb_dataset_download_id` | FK → `rhb_dataset_downloads`, nullable | ✓ | `nullOnDelete()`。どのダウンロードスナップショットから検出されたイベントかの出典情報 |
| `created_at` / `updated_at` | datetime | - | |

インデックス: `(event_type, occurred_on)`、`(origin, event_type, occurred_on)`。「2026年1月に新規開業した施設一覧」は次のクエリで取得できる。

```sql
SELECT mf.*
FROM medical_facility_events e
JOIN medical_facilities mf ON mf.id = e.medical_facility_id
WHERE e.event_type = 1 -- Created
  AND e.origin = 2     -- Detected（初回取込・再処理を除く）
  AND e.occurred_on BETWEEN '2026-01-01' AND '2026-01-31'
```

診療科目単位のイベント（旧`department_code`カラム）は、診療科目が独立したレコードではなく施設に紐づく大分類タグの配列になったことに伴い廃止した。診療科目の変化は`department_categories`カラムの差分として、施設単位の`Updated`イベントのpayloadに含まれる。

## `municipalities`

市区町村マスタ。デジタル庁アドレス・ベース・レジストリの市区町村マスター（廃止済みを除く）から作成した`database/seeders/data/municipalities.csv`を`MunicipalitySeeder`で投入する（`kanji_variants`と同じ運用）。

| カラム | 型 | 説明 |
|---|---|---|
| `code` (PK) | char(5) | 全国地方公共団体コード5桁（ABRの6桁コードから検査数字を除いたもの） |
| `prefecture_code` | char(2) | 都道府県コード。インデックスあり |
| `name` | string | 郡＋市＋区の結合表記（例: 札幌市中央区、磯城郡三宅町） |
| `name_kana` | string | 同カナ |

CSVを更新してシーダーを再実行したあとは、`facilities:assign-municipalities`で既存施設に反映する（キューワーカーの再起動も同コマンドが行う）。出典はデジタル庁（CC BY 4.0）で、APIの`meta.attribution.address_source`に表示している。

## `medical_info_net_locations`

厚生労働省「医療情報ネット」のオープンデータ（年2回、6月・12月に公開。PDL1.0）の施設の座標と定休日。`medical-info-net:import`が最新の版で`medical_info_net_schedules`と一緒に丸ごと入れ替える（取り込み済みの版は飛ばすので、毎月2日に確認する）。範囲外（`RHB_PREFECTURES`）の都道府県は入れない。座標が「0.0」の施設（元データの約8%）も、名称での照合で同名の別施設と取り違えないよう、座標なしで入れる。医療情報ネットの施設は厚生局データと共通のコードを持たないため、照合用のキーで突き合わせる（キーの作り方を変えたら`medical-info-net:import --force`で作り直す）（`App\Services\MedicalInfoNet\MedicalInfoNetMatcher`。下記「ジオコーディング」の5と同じ規則）。

| カラム | 型 | 説明 |
|---|---|---|
| `id` | bigint (PK) | 内部主キー |
| `source_id` | string(20) / null | 医療情報ネットの施設ID。診療時間票（`medical_info_net_schedules`）との対応に使う。この列を追加する前に取り込んだ行はnull（`medical-info-net:import --force`で埋まる） |
| `institution_type` | unsignedTinyInteger | `App\Enums\InstitutionType`。病院・診療所・歯科診療所・薬局の各ファイルに対応 |
| `municipality_code` | char(5) | 都道府県コード＋市区町村コード（元データの3桁）。`medical_facilities.municipality_code`と同じ体系 |
| `name_key` | string | `App\Services\Address\FacilityMatchingKeys::name()`。正規化した名称から、先頭の法人名（「医療法人社団明生会」「社団医療法人養生会」、医療情報ネットの略記「(医)成心会」）と記号（「・」「、」など）を除き、「皮フ」「付属」を「皮膚」「附属」に、ハイフンを長音記号にそろえたもの |
| `address_key` | string | `FacilityMatchingKeys::address()`。都道府県名と、番地より後ろ（建物名・階）、「大字」「字」を除き、町名の漢数字（「五条」）を数字に、番地を「1-2-3」の形にした所在地 |
| `latitude` / `longitude` | decimal(10,6) / null | 元データの所在地座標。「0.0」はnull |
| `closures` | json / null | 定休日 `{weekly: ["sun"], monthly: [{week: 2, day: "wed"}], holidays: bool|null, other: string|null}`。元データの「毎週決まった曜日に休診」などの列は、名前に反して**1が診療・0が休診**（定義書のとおり）。`monthly`は毎週の休みと重なる曜日を除く。`other`は自由記述（元データの「（改行）」は改行にする）。該当する列がすべて空ならnull |
| `published_on` | date | 元データの公開時点（例: 2026-06-01） |
| `created_at` / `updated_at` | datetime | |

インデックス: `(municipality_code, institution_type)`、`source_id`。取り込むと、座標が町丁目・医療情報ネット・国土数値情報・なしの施設の`geocoded_address`を空にし、次の`facilities:geocode`で付け直す（`updated_at`は座標が実際に変わった施設だけ動く）。

## `medical_info_net_schedules`

医療情報ネットの施設ごとの診療時間（`App\Services\MedicalInfoNet\MedicalInfoNetHours`）。照合できた施設（`medical_facilities.medical_info_net_id`）について、診療時間のAPIがそのまま返し、`facilities:assign-opening-hours`が`medical_facility_opening_periods`を作る。病院・診療所・歯科診療所は「診療科・診療時間票」（診療科×時間帯1〜3ごとの行。施設ごとにまとまって並ぶ前提で、同じIDが離れて現れたら取り込みを失敗させる）、薬局は施設票の開店時間帯1〜4から作る。

| カラム | 型 | 説明 |
|---|---|---|
| `id` | bigint (PK) | 内部主キー |
| `source_id` | string(20) | 医療情報ネットの施設ID（`medical_info_net_locations.source_id`）。ユニーク |
| `schedules` | json | `[{departments: ["内科", "小児科"], slots: [{number: 1, days: [{day: "mon", opens: "09:00", closes: "12:30", reception_opens: "08:45", reception_closes: "12:00"}]}]}]`。同じ時間の診療科を1つにまとめる。時刻がない時間帯・曜日は含めない。時刻は元データのまま（`HH:MM`以外は捨てる。終了が開始より早い曜日の時刻が全国で約3,000件ある）。薬局は`departments`が空で受付時刻はnull |
| `created_at` / `updated_at` | datetime | |

全国の取り込み（2026年6月版、ローカルで約1分）: 203,903施設、うち診療時間あり201,196施設。指定中の施設のうち診療時間を返せるのは、病院93.7%・診療所81.3%・歯科診療所79.4%・薬局94.0%（2026-10-05計測）。

## `national_land_medical_locations`

国土交通省「国土数値情報（医療機関）」（P04、2020年度。CC BY 4.0）の病院・診療所・歯科診療所の位置。地図から読み取った位置で、薬局は含まない。更新されないデータのため定期実行はせず、`national-land:import`を一度実行する（再実行すると丸ごと入れ替える）。範囲外（`RHB_PREFECTURES`）の都道府県は取得しない。列は`medical_info_net_locations`と同じ作りで、照合も同じ`MedicalInfoNetMatcher`で行う（下記「ジオコーディング」の6）。

| カラム | 型 | 説明 |
|---|---|---|
| `id` | bigint (PK) | 内部主キー |
| `institution_type` | unsignedTinyInteger | `App\Enums\InstitutionType`。元データの医療機関分類（1:病院 2:一般診療所 3:歯科診療所）がそのまま対応する |
| `municipality_code` | char(5) | 元データに市区町村コードがないため、所在地（市区町村名から始まる）を`MunicipalityResolver`で読んだもの。読めない行（市区町村名が省かれた所在地など。全国で約2%）は入れない |
| `name_key` / `address_key` | string | `medical_info_net_locations`と同じ`FacilityMatchingKeys`の照合用キー。ただし`address_key`は市区町村より後ろの部分から作る（元データは横浜市の区を「戸塚区…」と市名を省いて書くなど、市区町村の書き方が厚生局データと違うことがあるため。照合する施設側も同じ部分で比べる） |
| `latitude` / `longitude` | decimal(10,6) | 元データの位置 |
| `created_at` / `updated_at` | datetime | |

インデックス: `(municipality_code, institution_type)`。取り込むと、座標が町丁目・国土数値情報・なしの施設の`geocoded_address`を空にし、次の`facilities:geocode`で付け直す。

## `medical_facility_opening_periods`

施設が開いている曜日・時間帯（一覧APIの`open_at`の判定用）。`facilities:assign-opening-hours`が、照合できた医療情報ネットの診療時間から作り直す（`App\Services\MedicalInfoNet\OpeningPeriods`）。施設・医療情報ネットとも前回から変わっていなければ（件数と`updated_at`・ID の最大値で判定）飛ばす。市区町村コードだけを付け直したとき（`facilities:assign-municipalities`）は`updated_at`が動かないため、`--force`で作り直す。

| カラム | 型 | 説明 |
|---|---|---|
| `id` | bigint (PK) | 内部主キー |
| `medical_facility_id` | bigint (FK) | `medical_facilities.id`（施設の削除で消える） |
| `day` | unsignedTinyInteger | 1（月）〜7（日）、8は祝日 |
| `opens` / `closes` | time | 開始・終了（終了は含まない。日付をまたぐ時間帯は24:00で切り、翌日の0:00からの行を加える） |
| `weeks` | unsignedTinyInteger | 対象の週（ビット0が第1週〜ビット4が第5週）。「第2水曜休診」なら水曜の行のビット1を落とす。通常は31 |

作り方: 診療科ごと・曜日ごとに、受付時間があれば受付時間、なければ診療時間を使い、全診療科を重ねて1日の時間帯にまとめる（どれかの科が開いていれば開いている）。毎週の休み・祝日の休みは、時刻が載っていてもその曜日の行を作らない（安全側）。自由記述の休み（年末年始など）は使わない。インデックス: `(medical_facility_id, day, opens)`、`(day, opens, closes, weeks, medical_facility_id)`（`open_at`用。判定に使う列をすべて含むので、全国の`open_at`検索の件数取得がローカルで約0.3秒→約0.09秒）。全国で約164万行、作り直しはローカルで約50秒（2026-10-05計測）。

## `public_holidays`

内閣府「国民の祝日について」の祝日一覧（政府標準利用規約、CC BY 4.0 互換）。1955年から翌年末まで（約1,070日。振替休日・国民の休日を含む）。`holidays:import`が毎月4日に丸ごと入れ替える（1,000日未満しか読めなければ失敗させ、前の一覧を残す）。`GET /api/v1/holidays`で返し、`open_at`ではこの日を「祝」の時刻で判定する。

| カラム | 型 | 説明 |
|---|---|---|
| `id` | bigint (PK) | 内部主キー |
| `date` | date | 祝日（ユニーク） |
| `name` | string | 名称（元日、休日 など） |
| `created_at` / `updated_at` | datetime | |

## `municipality_populations`

総務省「住民基本台帳に基づく人口、人口動態及び世帯数」の市区町村別の人口（総計＝日本人住民と外国人住民の計、毎年1月1日時点）。集計API（`/api/v1/stats/facilities?group_by=municipality`）の人口1万人あたりの件数に使う。`population:import`が最新の版で丸ごと入れ替える（年1回、夏ごろの公開。取り込み済みの版は飛ばすので、毎月3日に確認する）。`municipalities`はアドレス・ベース・レジストリから投入し直すため、人口は別の表に持つ。

| カラム | 型 | 説明 |
|---|---|---|
| `municipality_code` | char(5) (PK) | 元データの6桁の団体コードから末尾の検査数字を除いたもの。`municipalities.code`と同じ体系（政令指定都市は市と各区の両方がある） |
| `population` | unsignedInteger | 人口（総計） |
| `as_of` | date | 基準日（例: 2026-01-01） |
| `created_at` / `updated_at` | datetime | |

- 都道府県の合計（団体コードが`xx000x`）と全国の合計は入れない。
- 住民登録上の人口のため、昼間人口の多い都心部（千代田区など）や、人口の少ない町村では、人口あたりの件数が極端な値になる。

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
| `imported_at` | datetime | ✓ | 取込（`rhb:import`）が失敗行なしで完了した日時。局×カテゴリの現行ダウンロードがすべて取込済みなら、日次の`rhb:import`はそのデータセットをスキップする（`--force`で強制再取込）。失敗行があった場合は設定せず、翌日の実行で再取込される |
| `created_at` / `updated_at` | datetime | - | |

`unique(bureau_code, category, filename, published_on)`により、同一バージョンの重複ダウンロード・重複行を防ぐ（北海道のようにファイル名が毎月変わらない局があるため、公開日もキーに含める）。「展開後の県×カテゴリ×ファイル」単位の状態は別テーブルに持たず、インポート実行のたびに`BundleExpander`で決定論的に再導出する（状態がドリフトする余地を増やさないため）。

## `kanji_variants`

漢字の異体字（例: 髙⇄高）を検索用に統合するためのマスタテーブル。`name_normalized`の計算に使う。データソースの変更とは無関係に維持している基盤テーブル。

| カラム | 型 | NULL | 説明 |
|---|---|---|---|
| `id` | bigint (PK) | - | 内部主キー |
| `variant_character` | string(8), unique | - | 異体字（1文字） |
| `canonical_character` | string(8) | - | 正規化後の標準字体（1文字） |
| `source` | string | - | `kJapaneseNewVariant` / `kJapaneseOldVariant`（Unicodeコンソーシアムの Unihanデータベース由来）/ `manual`（個別に検証して手動追加したもの） |
| `created_at` / `updated_at` | datetime | - | |

368件（自動抽出364件＋手動補完4件）を`KanjiVariantSeeder`でシードする。Unihan由来のデータは[Unicode License v3](database/seeders/data/LICENSE-Unicode.txt)に従うため、CSVを配布する際はこのライセンス文を同梱すること。データの選定経緯:

- 当初検討した「住基統一文字コード 正字対応表」（政府PDF）は、私用領域(PUA)の古いレガシーコードを実在のUnicode文字に対応付けるための表であり、「髙⇄高」のような既存のUnicode文字同士の異体字統合には使えないと判明したため採用しなかった
- Unicodeコンソーシアム公式のUnihanデータベース（`Unihan_Variants.txt`）の`kJapaneseOldVariant`/`kJapaneseNewVariant`フィールド（日本語の旧字体→新字体に特化、364件）を自動抽出のコアとして採用
- より広い`kSemanticVariant`フィールドは、多段連鎖させると本来別字として扱うべき文字まで誤って統合してしまうリスクが判明したため自動抽出には使わず、個別に検証した4件（髙→高、﨑→崎、嵜→崎、邉→辺）のみ手動で補完した

### 正規化ロジック（`App\Services\Text\ItaijiNormalizer`）

1. `Normalizer::normalize($value, Normalizer::FORM_KC)`（PHPの`intl`拡張）でNFKC正規化（全角英数字・全角スペース等を半角に統一）
2. `kanji_variants`のマッピングで異体字を標準字体に置換（`strtr()`、`once()`でインスタンス単位にメモ化）

`MedicalFacility`保存時（`saving`イベント）に`MedicalFacilityObserver`が`name`の変更を検知して自動的に`name_normalized`を計算する。`DatabaseSeeder`は`WithoutModelEvents`を使用しているため、将来`MedicalFacility`を一括生成するインポート処理で同様の設定を使う場合は、正規化カラムが自動計算されない点に注意（明示的に`ItaijiNormalizer`を呼び出す必要がある）。

#### 異体字マスタを更新するとき

Observerは`name`/`address`が変わった時しか正規化カラムを再計算しないため、マッピングを追加・修正しても既存の施設には反映されない。次の手順で反映する。

1. `database/seeders/data/itaiji-mapping.csv`を編集する
2. `sail artisan db:seed --class=KanjiVariantSeeder` — `variant_character`をキーにupsertするため再実行できる（CSVから削除した行はテーブルに残るので、削除する場合は手動で消す）
3. `sail artisan facilities:renormalize` — 全施設の`name_normalized`/`address_normalized`を再計算する（`updated_at`は変更しない）。`ItaijiNormalizer`はマッピングをプロセス単位でメモ化しているため、最後に`queue:restart`で常駐のキューワーカーに再起動を指示する

異体字変換を別APIとして切り出すことも検討したが、現時点では利用者がこのアプリ1つのみでありYAGNIと判断し、アプリ内に閉じて実装した。

### 住所正規化ロジック（`App\Services\Text\AddressNormalizer`）

`ItaijiNormalizer`を合成し（NFKCで全角数字・全角ハイフンを半角化）、その後に住所特有のルールを1点追加する: 実データで番地区切りにカタカナ長音記号「ー」が誤用されているケース（例: `丸塚町１５７ー１`）を、数字に前後を挟まれている場合のみハイフンへ変換する。`ガーデンハウス`のような建物名内の正当な長音記号はカナに前後を挟まれるため対象外——機械的に判別できるのはこの文脈のみと実データで確認済み。`name_normalized`と同じく`MedicalFacilityObserver`が`address`の変更を検知して自動計算する。

## ジオコーディング（`App\Services\Geocoding\FacilityGeocoder`）

デジタル庁アドレス・ベース・レジストリ（ABR、CC BY 4.0）の次のファイルで住所から座標を求める。ファイルはS3（`config/abr.php`）から取得してローカルディスクの`abr/`に保存し、次回からはETagで再検証する（変わっていなければ再取得しない）。参照データは全国で数千万行あるため、DBには入れず、都道府県ごとに1回ずつ流し読みして、対象の施設に必要な行だけを使う。

| データセット | 単位 | 用途 |
|---|---|---|
| 町字マスター（`mt_town`）・位置参照（`mt_town_pos`） | 都道府県 | 町字の特定、住居表示の実施有無、町丁目の代表点 |
| 住居表示 街区（`mt_rsdtdsp_blk`）・位置参照 | 都道府県 | 〇番 |
| 住居表示 住居（`mt_rsdtdsp_rsdt`）・位置参照 | 都道府県 | 〇番〇号 |
| 地番マスター（`mt_parcel`）・位置参照 | 市区町村 | 〇番地〇（対象の施設がある市区町村のファイルだけ取得） |

1. `MunicipalityResolver::match()`で市区町村と残りの住所を得る（ABRの6桁コードは`mt_town`から引く）
2. `TownMatcher`で町字を特定する。吸収する表記の違いは次のとおり（いずれも全国の実データで確認したもの）
   - ABRの「大字北堀」「字豊見城」（沖縄など）と、住所の「北堀」「豊見城」
   - 小字の有無（「大字鶴賀字田町」を「鶴賀田町」「田町」と書く）。ただし小字を省いた表記は、それが1つの町字に決まるときだけ使う（「福室」は丁目と多数の小字で共有されるため、「福室5-10-5」を小字と取り違えない）
   - 丁目の漢数字・算用数字と、丁目を省いたハイフン表記（「九段南1-6-5」）。丁目のある町は丁目なしの大字としても載っていることがあるため（「広小路」と「広小路一丁目」…）、ハイフン表記は丁目として先に解釈する
   - 京都市の通り名（「高倉通姉小路下ル東片町」）。そのままでは一致しないときだけ、「上ル・下ル・東入・西入」までを外して照合し直す
3. `BanchiParser`で番地の数字を読む。「6-5」が街区・住居か地番・枝番かは書き方では決まらないため、町字の住居表示フラグで決める
4. 住居表示の地区は住居 → 街区、それ以外は地番 → 同じ地番の別の枝番、の順に座標を探し、なければ町字の代表点を使う。小字には代表点がほとんどない（愛知県で53,566件中724件）ため、小字に代表点がなければ親の大字（町字IDの上4桁＋`000`）の代表点を使う

5. ABRで町丁目まで（または何も）求められなかった施設は、`MedicalInfoNetLocator`が医療情報ネットの座標で置き換える。種別・市区町村が同じ医療情報ネットの施設のうち、名称キーが一致するもの（複数あれば住所キーでも絞る）、なければ住所キーが一致して名称も似ているものが、ちょうど1件のときだけ使う。さらに、町丁目の代表点から2km以内（座標がない施設は、その市区町村の住居・街区レベルの施設の平均位置から30km以内）でなければ使わない。番地レベル（1〜4）の施設には使わない

**医療情報ネットの座標の実測**（2026-10-01、指定中の223,291件）: 1件に照合できたのは178,430件（79.9%）。ABRで番地レベルの施設と比べると、差の中央値は住居14m・街区40m・地番10m、90%点は49〜204mで、医療情報ネットの座標はほぼ番地単位の精度。一方で1km以上食い違う組（0.5〜1.9%）は、医療情報ネット側が外れている例のほうが多い（市区町村の中心からの距離で判定して、医療情報ネット側219件、ABR側107件。札幌市中央区の施設が約75km北にある例など）。そのため番地レベルではABRを優先し、医療情報ネットは町丁目以下の補完にだけ使う。組み込んだ結果（指定中の施設）: 番地レベル69.7%、医療情報ネット23.2%、町丁目6.4%、判定不能0.6%で、町丁目より細かい座標がある施設は69.7%から92.9%になった。医療情報ネットを使った施設を無作為に15件抜き出し、照合先の名称・住所がすべて同じ施設であることを確かめた。

6. それでも町丁目まで（または何も）しか求められない病院・診療所・歯科診療所は、`NationalLandLocator`が国土数値情報（医療機関）の位置で置き換える。照合は5と同じ規則（`MedicalInfoNetMatcher`）に加えて、住所キー（市区町村より後ろ）も一致することを条件にする（2020年度のデータのため、その後に移転した施設に古い位置を付けないように）。町丁目の代表点から10km以内（大きな大字では代表点から数kmの施設もあるため、5より広い。座標がない施設は5と同じく市区町村の平均位置から30km以内）でなければ使わない

**国土数値情報の位置の実測**（2026-10-11、ローカルの全国データ）: 市区町村を読めた177,365件を取り込んだ（所在地に市区町村名がない約2%は除外。横浜市は「戸塚区…」と市名を省いて書かれているため、`MunicipalityResolver`が政令市の区名だけでも読めるようにした）。名称・住所とも一致した施設で比べると、ABRで番地レベルの施設との差の中央値は8〜37m、10km以上離れていたのは約5.4万件中4件で、医療情報ネットと同等の精度。住所が一致しない組は差が大きく（住居レベルで90%点310m）、移転を含むため使わない。組み込んだ結果、薬局を除く指定中の施設のうち町丁目どまり11,270件と座標なし943件から、7,053件（うち横浜市325件）が国土数値情報の位置になり、町丁目より細かい座標がある施設は全体（薬局を含む）の93.1%から96.2%になった。福島県で町丁目の代表点から1.8km離れていた施設（福島市飯坂町の診療所）など、地方の大きな大字ほど改善が大きい。無作為に12件抜き出し、照合先の名称・住所がすべて同じ施設であることを確かめた。

町字ID（`machiaza_id`）は市区町村の中でしか一意でないため、照合のキーには必ず市区町村コードを含める。

町字マスターは都道府県で8万行を超えることがある（福島県は86,003行、ほぼ小字）ため、市区町村ごとに読んで照合しては捨て、PHPの既定のメモリ上限（128MB）に収める。ファイルは市区町村ごとにまとまって並んでいる前提で、崩れていれば誤った照合をせず例外にする。

**精度の実測**（2026-10-01、全国224,517件）: 番地レベル（住居・街区・地番）が69.7%（住居43.8%、街区5.4%、地番14.7%、地番の枝番なし5.8%）、町丁目が28.4%、判定不能が1.9%。東京都は番地レベル85%、判定不能0.4%。番地レベルが低いのは京都府（18%、通り名の住所が多く住居表示が少ない）、愛知県（31%）、岐阜県（32%）。地番の座標は法務省地図が電子化された地域にしかなく（千代田区は地番の約1%、八王子市は約17%）、そうした地域では町丁目がオープンデータで得られる上限になる。判定不能が多いのは宮崎県（15%）で、ABRに町字が載っていない（宮崎市大塚町など）、小字の名前が住所と合わない（「熊野正蓮寺1番地」に対しABRは「正蓮寺二番」）といった、元データ側の差による。測地系はJGD2000とJGD2011が混在しているが、差は数メートル程度のため変換していない。

## 設計上の注意点

- 地方厚生局データには開設者・管理者の氏名や指定年月日は含まれるが、法人番号のような構造化された運営法人IDは含まれない。`founder_name`は原本の表記（法人名＋代表者名が1文字列に混在することがある）をそのまま保持している。
- 都道府県コードはあえて正規化せず、コード文字列のまま保持する方針を継続している。
- 診療科目は時間帯付きの構造化データを持たない（旧`医療機能情報提供制度`データにあった診療時間・受付時間の情報は、地方厚生局データには存在しない）。
- `latitude`/`longitude`は原本に存在しないため、取込では書き込まない。`facilities:geocode`だけが書き込む。

## データソースの変遷

1. **旧: 医療機能情報提供制度CSV（フェーズ1〜4、PR #6〜#10、全て破棄済み）** — 厚生労働省が全国一本で公開するCSVを情報源としていた。診療科目の時間帯情報を含む豊富なデータだったが、電話番号・郵便番号・開設者情報が一切なく、実際の指定コードとしての信頼性も低い（内部発番のオープンデータ用IDで、公式な構造説明が存在しないことをMHLW公式の定義書で確認した）ことが判明し、破棄した。
2. **現行: 地方厚生局の保険医療機関指定一覧（フェーズA以降）** — 8つの地方厚生局がそれぞれ独立して公開する「コード内容別医療機関一覧表」を情報源とする。全国一本のCSVではなく、局ごとに異なるURL構造・ファイル形式（PDF/Excel、ZIP圧縮あり）・レイアウト差異（列ヘッダーなしの印刷帳票形式、1レコードが3〜14行の可変長物理行に渡る）を持つため、`app/Services/Rhb/Download/`配下に局ごとの`BureauLinkResolver`/`BundleExpander`実装を追加していく設計にしている。フェーズA（本フェーズ）は北海道1局のみを対象にアーキテクチャを検証するパイロットで、`app/Services/Rhb/Import/`（DB非依存パース層）・`app/Services/Rhb/Sync/`（差分検出・upsert層、`App\Services\Sync\AttributeDiff`を共通利用）・`app/Jobs/ImportRhbFacilityListJob.php`・`rhb:download`/`rhb:import`コマンドで構成される。フェーズB以降で残り7局のダウンロード層を追加し、8局全ての実装が完了している（パース層・Sync層は最後まで変更不要だった）。`rhb:download`/`rhb:import`は`routes/console.php`で毎日JST 5:00/5:30に定期実行されるようスケジュール済み（`rhb:import`は`Bus::batch()`でキューに投入するだけのため、実際の取り込みには別途永続的なキューワーカー——ローカルでは`composer run dev`が起動する`queue:listen`、本番相当の運用ではSupervisor等で管理する`queue:work`——が稼働している必要がある。スケジューラ自体はワーカーを起動しない）。REST API・認証は引き続き別フェーズで対応する。
3. **「10年スパンの運用に耐えるか」という観点**は継続して重視しており、全テーブルの`created_at`/`updated_at`等はMySQL `DATETIME`型（西暦9999年まで対応、32bit Unix時間に依存する`TIMESTAMP`型の2038年問題を回避）で最初から作成している。

## データの出典・利用条件

地方厚生局の公開データは、各局サイトの利用規約ページ（例: [北海道厚生局 利用規約・リンク・著作権等](https://kouseikyoku.mhlw.go.jp/hokkaido/aboutus/copyright.html)）で確認した通り、「公共データ利用規約（第1.0版）」（PDL1.0）に準拠しており、リンクフリー・二次利用（加工・編集を含む）ともに許可されている（商用利用の制限・再配布禁止の記載なし）。ただし以下2点が利用条件として明記されているため、このデータを画面・API等で利用者に提示する場合は遵守すること。

1. **出典の記載が必須**：例）出典：「（局名の）内の保険医療機関・保険薬局の指定一覧」（○○厚生局）（当該ページのURL）
2. **加工・編集した場合はその旨の記載も必須**：例）「北海道内の保険医療機関・保険薬局の指定一覧」（北海道厚生局）を加工して作成。また、加工後の情報をあたかも国（または府省等）が作成したかのような態様で公表・利用することは禁止されている。

本アプリは取得したデータを構造化・分類（診療科目の大分類化等）した上でデータベースに格納しており、上記の「加工・編集」に該当する。

市区町村の人口（`municipality_populations`）は総務省のサイトのコンテンツで、政府標準利用規約（CC BY 4.0 互換）に従い、出典を記載すれば加工・再配布できる。`meta.attribution.population_source`に出典を含めている。

国土数値情報（医療機関）（`national_land_medical_locations`）はCC BY 4.0で、出典と加工の旨を記載すれば加工・再配布できる。`meta.attribution.national_land_source`に「「国土数値情報（医療機関データ）」（国土交通省）を加工して作成」と出典URLを含めている。

APIでは次のように扱っている。

- **出典・加工の表示**: すべてのレスポンスの`meta.attribution`に、加工した旨の文言（`notice`）と各局の出典URL（`sources`）を含める
- **提供条件**: 加工したデータに独自のライセンスは付けず、元データと同じPDL1.0に準拠して提供する（`meta.attribution.license`）。利用者にも出典・加工の表示を引き継いでもらう
- **免責**: データの正確性・完全性は保証しない旨を`meta.attribution.disclaimer`に含める
- **個人名**: `founder_name`/`administrator_name`は個人名を含むため、DBには保持するがAPIでは返さない。元データで公開されている情報だが、氏名で取得できる形で再提供すると個人情報保護法上の扱いが変わりうること、施設検索という用途に不要であることによる
