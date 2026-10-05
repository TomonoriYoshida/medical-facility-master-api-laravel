<?php

namespace Tests\Unit\Services\AccessLog;

use App\Services\AccessLog\ScannerPaths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ScannerPathsTest extends TestCase
{
    #[DataProvider('paths')]
    public function test_it_tells_scanner_paths_from_ones_a_visitor_might_ask_for(string $path, bool $isScanner): void
    {
        $this->assertSame($isScanner, ScannerPaths::matches($path));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function paths(): array
    {
        return [
            '.env' => ['/.env', true],
            '.env in a directory' => ['/api/.env.local', true],
            '.git' => ['/.git/config', true],
            '.vscode' => ['/.vscode/sftp.json', true],
            'encoded dot' => ['/%2Eenv', true],
            'php script' => ['/info.php', true],
            'php script with a path after it' => ['/xmlrpc.php/x', true],
            'WordPress' => ['/wp-admin/setup-config.php', true],
            'WordPress under a directory' => ['/blog/wp-includes/wlwmanifest.xml', true],
            'cgi-bin' => ['/cgi-bin/luci', true],
            'phpMyAdmin' => ['/phpmyadmin/', true],
            'database dump' => ['/backup.sql', true],
            'backup' => ['/db.bak', true],
            'the front controller' => ['/index.php', false],
            '.well-known' => ['/.well-known/security.txt', false],
            'API' => ['/api/v1/medical-facilities', false],
            'bulk download file' => ['/api/v1/exports/13.csv.gz', false],
            'docs' => ['/docs/api.json', false],
            'README' => ['/README.md', false],
            'config.json' => ['/config.json', false],
            'a word starting with wp' => ['/wpa', false],
        ];
    }
}
