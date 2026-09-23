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
}
