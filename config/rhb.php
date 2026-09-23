<?php

use App\Enums\RhbBureau;
use App\Services\Rhb\Download\ChugokuShikokuBundleExpander;
use App\Services\Rhb\Download\ChugokuShikokuLinkResolver;
use App\Services\Rhb\Download\HokkaidoLinkResolver;
use App\Services\Rhb\Download\KantoShinetsuBundleExpander;
use App\Services\Rhb\Download\KantoShinetsuLinkResolver;
use App\Services\Rhb\Download\KinkiBundleExpander;
use App\Services\Rhb\Download\KinkiLinkResolver;
use App\Services\Rhb\Download\KyushuBundleExpander;
use App\Services\Rhb\Download\KyushuLinkResolver;
use App\Services\Rhb\Download\MultiSheetBundleExpander;
use App\Services\Rhb\Download\ShikokuBundleExpander;
use App\Services\Rhb\Download\ShikokuLinkResolver;
use App\Services\Rhb\Download\SingleFileBundleExpander;
use App\Services\Rhb\Download\TohokuLinkResolver;
use App\Services\Rhb\Download\TokaiHokurikuBundleExpander;
use App\Services\Rhb\Download\TokaiHokurikuLinkResolver;

return [

    /*
    |--------------------------------------------------------------------------
    | Regional Health Bureaus (地方厚生局)
    |--------------------------------------------------------------------------
    |
    | Each bureau publishes its own "コード内容別医療機関一覧表" page, with a
    | different URL structure and file-bundling shape -- there is no shared
    | index the way the old MHLW pipeline had, so each bureau gets its own
    | resolver/expander implementation. Bureaus are added incrementally by
    | adding an entry here plus a resolver/expander pair, without touching
    | the Import/Sync layers or the Job that drives them.
    |
    */

    'bureaus' => [
        'hokkaido' => [
            'label' => '北海道厚生局',
            'bureau' => RhbBureau::Hokkaido,
            'index_url' => 'https://kouseikyoku.mhlw.go.jp/hokkaido/gyomu/gyomu/hoken_kikan/code_ichiran.html',
            'base_url' => 'https://kouseikyoku.mhlw.go.jp',
            'prefecture_codes' => ['01'],
            'resolver' => HokkaidoLinkResolver::class,
            'expander' => SingleFileBundleExpander::class,
        ],
        'tohoku' => [
            'label' => '東北厚生局',
            'bureau' => RhbBureau::Tohoku,
            'index_url' => 'https://kouseikyoku.mhlw.go.jp/tohoku/gyomu/gyomu/hoken_kikan/itiran.html',
            'base_url' => 'https://kouseikyoku.mhlw.go.jp',
            'prefecture_codes' => ['02', '03', '04', '05', '06', '07'],
            'resolver' => TohokuLinkResolver::class,
            'expander' => MultiSheetBundleExpander::class,
        ],
        'kantoshinetsu' => [
            'label' => '関東信越厚生局',
            'bureau' => RhbBureau::KantoShinetsu,
            'index_url' => 'https://kouseikyoku.mhlw.go.jp/kantoshinetsu/chousa/shitei.html',
            'base_url' => 'https://kouseikyoku.mhlw.go.jp',
            'prefecture_codes' => ['08', '09', '10', '11', '12', '13', '14', '15', '19', '20'],
            'resolver' => KantoShinetsuLinkResolver::class,
            'expander' => KantoShinetsuBundleExpander::class,
        ],
        'tokaihokuriku' => [
            'label' => '東海北陸厚生局',
            'bureau' => RhbBureau::TokaiHokuriku,
            'index_url' => 'https://kouseikyoku.mhlw.go.jp/tokaihokuriku/newpage_00287.html',
            'base_url' => 'https://kouseikyoku.mhlw.go.jp',
            'prefecture_codes' => ['16', '17', '21', '22', '23', '24'],
            'resolver' => TokaiHokurikuLinkResolver::class,
            'expander' => TokaiHokurikuBundleExpander::class,
        ],
        'kinki' => [
            'label' => '近畿厚生局',
            'bureau' => RhbBureau::Kinki,
            'index_url' => 'https://kouseikyoku.mhlw.go.jp/kinki/tyousa/shinkishitei.html',
            'base_url' => 'https://kouseikyoku.mhlw.go.jp',
            'prefecture_codes' => ['18', '25', '26', '27', '28', '29', '30'],
            'resolver' => KinkiLinkResolver::class,
            'expander' => KinkiBundleExpander::class,
        ],
        'chugokushikoku' => [
            'label' => '中国四国厚生局',
            'bureau' => RhbBureau::ChugokuShikoku,
            'index_url' => 'https://kouseikyoku.mhlw.go.jp/chugokushikoku/chousaka/iryoukikanshitei.html',
            'base_url' => 'https://kouseikyoku.mhlw.go.jp',
            'prefecture_codes' => ['31', '32', '33', '34', '35'],
            'resolver' => ChugokuShikokuLinkResolver::class,
            'expander' => ChugokuShikokuBundleExpander::class,
        ],
        'shikoku' => [
            'label' => '四国厚生局',
            'bureau' => RhbBureau::Shikoku,
            'index_url' => 'https://kouseikyoku.mhlw.go.jp/shikoku/gyomu/gyomu/hoken_kikan/shitei/index.html',
            'base_url' => 'https://kouseikyoku.mhlw.go.jp',
            'prefecture_codes' => ['36', '37', '38', '39'],
            'resolver' => ShikokuLinkResolver::class,
            'expander' => ShikokuBundleExpander::class,
        ],
        'kyushu' => [
            'label' => '九州厚生局',
            'bureau' => RhbBureau::Kyushu,
            'index_url' => 'https://kouseikyoku.mhlw.go.jp/kyushu/gyomu/gyomu/hoken_kikan/index_00006.html',
            'base_url' => 'https://kouseikyoku.mhlw.go.jp',
            'prefecture_codes' => ['40', '41', '42', '43', '44', '45', '46', '47'],
            'resolver' => KyushuLinkResolver::class,
            'expander' => KyushuBundleExpander::class,
        ],
    ],

];
