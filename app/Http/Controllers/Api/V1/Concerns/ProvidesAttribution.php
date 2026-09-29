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
