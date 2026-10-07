<?php

namespace App\Enums;

// The 8 regional health bureaus (地方厚生局) that each independently
// publish their own jurisdiction's insured medical institution list.
//
// The docblock below is published as the API docs' schema description.

/**
 * 施設の指定一覧を公開している地方厚生局。各局が管轄する都道府県の一覧を公開しています（中国四国厚生局は中国5県、四国厚生局は四国4県）。
 */
enum RhbBureau: int
{
    case Hokkaido = 1;
    case Tohoku = 2;
    case KantoShinetsu = 3;
    case TokaiHokuriku = 4;
    case Kinki = 5;
    case ChugokuShikoku = 6;
    case Shikoku = 7;
    case Kyushu = 8;

    /**
     * Reads the Japanese display name from config/rhb.php rather than
     * duplicating it here, since that file is already the source of truth
     * for each bureau's label (and its source URL, used for API attribution).
     */
    public function label(): string
    {
        return collect(config()->array('rhb.bureaus'))->firstWhere('bureau', $this)['label'];
    }
}
