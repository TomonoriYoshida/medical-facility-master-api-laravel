<?php

namespace App\Services\AccessLog;

/**
 * Paths only a vulnerability scanner asks for: this application has no
 * dotfiles to serve (but .well-known), no PHP scripts besides its front
 * controller, and no WordPress, CGI or database admin. Paths a real visitor
 * might mistype or guess (README.md, config.json) are not on the list, and
 * neither are the bulk download files (.csv.gz, .jsonl.gz).
 */
final class ScannerPaths
{
    private const string PATTERN = '#(?:^|/)\.(?!well-known(?:/|$))[^/]|\.php(?:$|/)|(?:^|/)(?:wp-[a-z]+|cgi-bin|phpmyadmin|pma)(?:/|$)|\.(?:sql|bak)$#i';

    public static function matches(string $path): bool
    {
        $path = '/'.ltrim(rawurldecode($path), '/');

        return $path !== '/index.php' && preg_match(self::PATTERN, $path) === 1;
    }
}
