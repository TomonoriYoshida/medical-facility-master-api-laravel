<?php

namespace App\Services\Geocoding;

/**
 * Reads the leading numbers of the part of an address after its 町字:
 * "6番5号", "6-5-301", "545番地3", "1の189", "157番地". In a 住居表示 area
 * they are the 街区 and 住居 numbers, elsewhere the 地番 and its 枝番 --
 * which one is decided by the 町字, not by how they are written, since
 * "6-5" is used for both.
 */
final class BanchiParser
{
    /**
     * @return array{0: string, 1: string|null}|null [first number, second number]
     */
    public function parse(string $rest): ?array
    {
        $rest = ltrim($rest);

        // A 小字 the registry does not list ("字大門123") precedes the number.
        // Kanji only, so that a building name ("ビル1階") is not mistaken for one.
        if (preg_match('/^字?\p{Han}{1,6}(?=\d)/u', $rest, $matches) === 1) {
            $rest = substr($rest, strlen($matches[0]));
        }

        if (preg_match('/^(\d+)(?:番地の?|番|-|の)?(\d+)?/u', $rest, $matches) !== 1) {
            return null;
        }

        return [ltrim($matches[1], '0') ?: '0', isset($matches[2]) ? (ltrim($matches[2], '0') ?: '0') : null];
    }
}
