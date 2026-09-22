<?php

namespace App\Services\Mhlw\Import;

use Generator;
use RuntimeException;

/**
 * Streams data rows out of an MHLW CSV file without ever extracting the zip
 * or loading a full file into memory (some of these files are 600k+ rows).
 * Deliberately framework-free (takes a plain absolute path, not a
 * Storage disk) so it stays testable with plain PHPUnit\Framework\TestCase
 * like the rest of this parsing layer.
 */
final class MhlwCsvReader
{
    private readonly string $entryName;

    public function __construct(
        private readonly string $zipPath,
        ?string $entryName = null,
    ) {
        $this->entryName = $entryName ?? $this->inferEntryName($zipPath);
    }

    /**
     * @return Generator<int, array<int, string>>
     */
    public function rows(): Generator
    {
        $stream = fopen("zip://{$this->zipPath}#{$this->entryName}", 'r');

        if ($stream === false) {
            throw new RuntimeException("Unable to open \"{$this->entryName}\" inside \"{$this->zipPath}\".");
        }

        try {
            // The UTF-8 BOM (if present) is glued onto the header row's
            // first cell, and the header row is always discarded here
            // regardless of its content -- so it's unconditionally
            // dropped along with it. No explicit BOM detection/rewind is
            // needed (and zip:// streams don't support seeking anyway, so
            // a read-3-bytes-then-rewind approach silently corrupts data
            // when there's no BOM to find).
            fgetcsv($stream, null, ',', '"', '\\');

            while (($row = fgetcsv($stream, null, ',', '"', '\\')) !== false) {
                if ($row === null || $row === [null]) {
                    continue;
                }

                yield $row;
            }
        } finally {
            fclose($stream);
        }
    }

    private function inferEntryName(string $zipPath): string
    {
        $basename = basename($zipPath);

        return str_ends_with($basename, '.zip')
            ? substr($basename, 0, -4)
            : $basename;
    }
}
