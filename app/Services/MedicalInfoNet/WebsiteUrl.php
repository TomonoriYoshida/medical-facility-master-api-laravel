<?php

namespace App\Services\MedicalInfoNet;

use Normalizer;

/**
 * A facility's website as the 医療情報ネット lists it, made safe to serve as
 * a link. About 7% of the source's addresses are malformed: most lack the
 * scheme ("www.example.jp") or its colon ("http//www.example.jp"), which is
 * restored; anything else ("ﾅｼ", "htt://...") is dropped. Only http and
 * https are kept, so a client can put the value in an href as it is.
 */
final class WebsiteUrl
{
    public const int MAX_LENGTH = 500;

    public function normalize(string $value): ?string
    {
        $url = trim(Normalizer::normalize($value, Normalizer::FORM_KC) ?: $value);

        if ($url === '') {
            return null;
        }

        $url = preg_replace('#^(https?)//#i', '$1://', $url) ?? $url;

        // A bare host, optionally with a port, path or query: "www.example.jp/clinic/".
        if (preg_match('#^[a-z]+://#i', $url) !== 1 && preg_match('#^[a-z0-9-]+(\.[a-z0-9-]+)+(:\d+)?([/?\#]|$)#i', $url) === 1) {
            $url = 'http://'.$url;
        }

        // The scheme in lower case, so the value starts with "http://" or
        // "https://" as documented, and a Japanese path percent-encoded.
        $url = preg_replace_callback('#^[a-z]+(?=://)#i', fn (array $scheme): string => strtolower($scheme[0]), $url) ?? $url;
        $url = preg_replace_callback('/[^\x21-\x7E]+/', fn (array $text): string => rawurlencode($text[0]), $url) ?? $url;

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = (string) parse_url($url, PHP_URL_HOST);

        if (! in_array($scheme, ['http', 'https'], true)
            || preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/i', $host) !== 1
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || strlen($url) > self::MAX_LENGTH) {
            return null;
        }

        return $url;
    }
}
