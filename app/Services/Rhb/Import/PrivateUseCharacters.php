<?php

namespace App\Services\Rhb\Import;

/**
 * The bureaus' Excel files write some characters missing from JIS (𠮷,
 * 一点しんにょうの辻, 﨑...) as vendor 外字 in Unicode's Private Use Area,
 * which no font shows, so "𠮷田医院" read as "田医院". The same codes stand
 * for the same characters in every bureau's files.
 *
 * Each code's character was inferred (2026-10-05) by lining up the
 * facilities that contain it with their counterparts in the MHLW
 * 医療情報ネット (same type and municipality, names or addresses equal but
 * for that one character), keeping a code when one character clearly won:
 * at least twice and at least twice as often as any other, or the only one
 * found. They are written in their standard form (吉 for 𠮷), as the
 * 医療情報ネット writes them. Codes with no clear answer become "〓"
 * (げた), the usual mark for a character that cannot be shown.
 */
final class PrivateUseCharacters
{
    /**
     * Private Use Area code point => the character it stands for.
     */
    private const array CHARACTERS = [
        0xE061 => '土',
        0xE087 => '斎',
        0xE089 => '祐',
        0xE08E => '尻',
        0xE0A1 => '葛',
        0xE0A9 => '巽',
        0xE0AA => '簗',
        0xE0AC => '樋',
        0xE0AE => '塚',
        0xE0BE => '松',
        0xE0C5 => '辺',
        0xE0C7 => '片',
        0xE0CA => '真',
        0xE0E6 => '竜',
        0xE0E7 => '滝',
        0xE0E9 => '葛',
        0xE0EA => '葛',
        0xE0F4 => '角',
        0xE0F5 => '塚',
        0xE134 => '西',
        0xE14C => '潟',
        0xE165 => '鈴',
        0xE18D => '榊',
        0xE191 => '槙',
        0xE192 => '辻',
        0xE199 => '土',
        0xE1B1 => '芦',
        0xE1B4 => '辻',
        0xE1C4 => '槌',
        0xE1CE => '櫛',
        0xE1D1 => '逢',
        0xE1E6 => '辻',
        0xE222 => '増',
        0xE22B => '広',
        0xE234 => '鶏',
        0xE255 => '祁',
        0xE30D => '菅',
        0xE37D => '滝',
        0xE37E => '沢',
        0xE37F => '辺',
        0xE380 => '辺',
        0xE381 => '塚',
        0xE382 => '広',
        0xE38B => '吉',
        0xE398 => '辺',
        0xE39A => '高',
        0xE3A1 => '広',
        0xE3A6 => '静',
        0xE3C5 => '橋',
        0xE3CD => '祇',
        0xE3D6 => '辺',
        0xE3E8 => '藤',
        0xE3E9 => '原',
        0xE404 => '真',
        0xE405 => '㟢',
    ];

    private const string UNKNOWN = '〓';

    public function replace(string $value): string
    {
        return (string) preg_replace_callback(
            '/[\x{E000}-\x{F8FF}]/u',
            fn (array $matches): string => self::CHARACTERS[mb_ord($matches[0])] ?? self::UNKNOWN,
            $value,
        );
    }
}
