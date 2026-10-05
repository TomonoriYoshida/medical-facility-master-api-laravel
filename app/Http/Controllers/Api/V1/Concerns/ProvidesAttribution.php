<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Services\Rhb\RhbScope;

trait ProvidesAttribution
{
    /**
     * Source attribution required by the regional health bureaus' public
     * data terms (PDL1.0): DATABASE.md "データの出典・利用条件". Included on
     * every response regardless of which bureaus its facilities come from.
     * The processed data is itself offered under the same PDL1.0 terms
     * rather than a license of our own, so downstream users carry the
     * bureaus' attribution forward.
     *
     * @return array<string, mixed>
     */
    private function attribution(): array
    {
        return [
            'notice' => '本APIのデータは、各地方厚生局が公開する「保険医療機関・保険薬局の指定一覧」を加工して作成しています。',
            'license' => [
                'name' => '公共データ利用規約（第1.0版）',
                'url' => 'https://www.digital.go.jp/resources/open_data/public_data_license_v1.0',
            ],
            'disclaimer' => 'データの正確性・完全性は保証しません。最新かつ正確な情報は、各地方厚生局の公表資料を確認してください。',
            // 市区町村・座標の出典（デジタル庁 アドレス・ベース・レジストリ、CC BY 4.0）。
            'address_source' => [
                'name' => 'アドレス・ベース・レジストリ（デジタル庁）の市区町村・町字・住居表示・地番の各マスターと位置参照データを加工して作成',
                'url' => 'https://catalog.registries.digital.go.jp/rc/dataset/',
            ],
            // 町丁目までしか求められない施設の座標と、施設の診療時間の出典（厚生労働省、PDL1.0）。
            'medical_info_net_source' => [
                'name' => '厚生労働省「医療情報ネット」のオープンデータ（所在地座標・診療時間・休診日）を加工して作成',
                'url' => 'https://www.mhlw.go.jp/stf/seisakunitsuite/bunya/kenkou_iryou/iryou/newpage_43373.html',
            ],
            // 市区町村の人口の出典（総務省、政府標準利用規約・CC BY 4.0 互換）。集計APIの人口あたりの件数に使う。
            'population_source' => [
                'name' => '総務省「住民基本台帳に基づく人口、人口動態及び世帯数」（市区町村別）を加工して作成',
                'url' => 'https://www.soumu.go.jp/main_sosiki/jichi_gyousei/daityo/jinkou_jinkoudoutai-setaisuu.html',
            ],
            // The bureaus this installation actually draws from (RhbScope).
            'sources' => collect(app(RhbScope::class)->bureaus())
                ->map(fn (array $bureau) => [
                    'bureau' => $bureau['label'],
                    'url' => $bureau['index_url'],
                ])
                ->values(),
        ];
    }
}
