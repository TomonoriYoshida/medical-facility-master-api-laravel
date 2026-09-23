<?php

namespace App\Enums;

/**
 * The 8 regional health bureaus (地方厚生局) that each independently
 * publish their own jurisdiction's insured medical institution list.
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
        return collect(config('rhb.bureaus'))->firstWhere('bureau', $this)['label'];
    }
}
