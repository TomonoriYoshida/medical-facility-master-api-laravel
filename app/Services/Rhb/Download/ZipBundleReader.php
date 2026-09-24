<?php

namespace App\Services\Rhb\Download;

use Closure;
use Generator;
use RuntimeException;
use ZipArchive;

/**
 * Iterates the entries of a downloaded bundle zip for the BundleExpanders,
 * failing loudly on an unreadable entry instead of letting it flow on as an
 * empty filename or an empty extracted file (e.g. from a corrupted
 * download). ZipArchive signals this inconsistently: an unreadable name is
 * false, but a corrupted compressed body comes back as a *shorter string*
 * (verified: "" for a 700-byte entry, with status still "No error"), so
 * contents are checked against the entry's declared uncompressed size.
 */
final class ZipBundleReader
{
    /**
     * Yields entry name => a closure that reads that entry's contents, so an
     * expander only reads the entries it keeps. The zip stays open until the
     * iteration ends, including when the caller stops early.
     *
     * @return Generator<string, Closure(): string>
     */
    public function entries(string $zipPath, int $downloadId): Generator
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException("Unable to open zip \"{$zipPath}\" (download #{$downloadId}).");
        }

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entryName = $zip->getNameIndex($i);

                if ($entryName === false) {
                    throw new RuntimeException("Unable to read the name of entry #{$i} in zip \"{$zipPath}\" (download #{$downloadId}).");
                }

                yield $entryName => function () use ($zip, $i, $entryName, $zipPath, $downloadId): string {
                    $contents = $zip->getFromIndex($i);
                    $declaredSize = $zip->statIndex($i)['size'] ?? null;

                    if ($contents === false || strlen($contents) !== $declaredSize) {
                        throw new RuntimeException("Unable to read entry \"{$entryName}\" in zip \"{$zipPath}\" (download #{$downloadId}).");
                    }

                    return $contents;
                };
            }
        } finally {
            $zip->close();
        }
    }
}
