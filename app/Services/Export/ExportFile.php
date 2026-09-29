<?php

namespace App\Services\Export;

use RuntimeException;

/**
 * One gzip-compressed export file, written line by line so the whole
 * country never has to fit in memory.
 */
final class ExportFile
{
    /** @var resource */
    private $handle;

    private int $records = 0;

    public function __construct(
        public readonly string $path,
    ) {
        $handle = fopen("compress.zlib://{$path}", 'wb');

        if ($handle === false) {
            throw new RuntimeException("Could not open export file \"{$path}\".");
        }

        $this->handle = $handle;
    }

    public function writeLine(string $line): void
    {
        fwrite($this->handle, $line."\n");
        $this->records++;
    }

    /**
     * RFC 4180 CSV: fields quoted as needed, quotes doubled, CRLF line ends.
     *
     * @param  list<string|int|null>  $fields
     */
    public function writeCsv(array $fields, bool $countsAsRecord = false): void
    {
        fputcsv($this->handle, $fields, ',', '"', '', "\r\n");

        if ($countsAsRecord) {
            $this->records++;
        }
    }

    public function records(): int
    {
        return $this->records;
    }

    public function close(): void
    {
        fclose($this->handle);
    }
}
