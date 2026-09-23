<?php

namespace App\Services\Rhb\Import;

use Generator;
use RuntimeException;
use SimpleXMLElement;
use XMLReader;

/**
 * Streams rows out of one sheet of a .xlsx file via PHP's `zip://` stream
 * wrapper, without extracting the zip or loading a full sheet into memory.
 * Deliberately framework-free (a plain absolute path in, no Storage disk
 * coupling), matching this project's existing Import-layer convention.
 *
 * The source files are "print reports exported to Excel" (no formulas, no
 * rich formatting): every cell sampled across real government data was
 * confirmed shared-string typed (t="s") -- there is deliberately no
 * numeric/date/inline-string handling here, since none has been observed.
 */
final class RhbXlsxReader
{
    /** @var list<string>|null */
    private ?array $sharedStrings = null;

    public function __construct(
        private readonly string $xlsxPath,
    ) {}

    /**
     * @return list<string> sheet names in document order
     */
    public function sheetNames(): array
    {
        return array_map(
            static fn (array $sheet): string => $sheet['name'],
            $this->sheetManifest(),
        );
    }

    /**
     * @return Generator<int, array<int, string>> raw cell strings per row, 0-indexed by column
     */
    public function rows(?string $sheetName = null): Generator
    {
        $manifest = $this->sheetManifest();
        $sheet = $sheetName === null
            ? ($manifest[0] ?? throw new RuntimeException("\"{$this->xlsxPath}\" has no sheets."))
            : $this->findSheet($manifest, $sheetName);

        $sharedStrings = $this->sharedStrings();

        $reader = new XMLReader;

        if (! $reader->open("zip://{$this->xlsxPath}#xl/{$sheet['target']}")) {
            throw new RuntimeException("Unable to open sheet \"{$sheet['target']}\" inside \"{$this->xlsxPath}\".");
        }

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                    continue;
                }

                // readOuterXml() already advances the reader past this
                // row's subtree, so no further $reader->next()/read() call
                // is needed here -- calling next() as well would silently
                // skip every other row (verified empirically against a
                // real file: doing so produced only even-numbered rows).
                yield $this->parseRow($reader->readOuterXml(), $sharedStrings);
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * @param  list<string>  $sharedStrings
     * @return array<int, string>
     */
    private function parseRow(string $rowXml, array $sharedStrings): array
    {
        $row = new SimpleXMLElement($rowXml);
        $cells = [];

        foreach ($row->c as $c) {
            $index = $this->columnIndex((string) $c['r']);
            $type = (string) $c['t'];

            $cells[$index] = match ($type) {
                's' => $sharedStrings[(int) $c->v] ?? '',
                default => (string) $c->v,
            };
        }

        if ($cells === []) {
            return [];
        }

        $result = [];
        for ($i = 0; $i <= max(array_keys($cells)); $i++) {
            $result[$i] = $cells[$i] ?? '';
        }

        return $result;
    }

    private function columnIndex(string $cellRef): int
    {
        preg_match('/^([A-Z]+)\d+$/', $cellRef, $matches);

        $index = 0;

        foreach (str_split($matches[1]) as $char) {
            $index = $index * 26 + (ord($char) - ord('A') + 1);
        }

        return $index - 1;
    }

    /**
     * @return list<string>
     */
    private function sharedStrings(): array
    {
        if ($this->sharedStrings !== null) {
            return $this->sharedStrings;
        }

        $xml = file_get_contents("zip://{$this->xlsxPath}#xl/sharedStrings.xml");

        if ($xml === false) {
            return $this->sharedStrings = [];
        }

        $root = new SimpleXMLElement($xml);
        $strings = [];

        foreach ($root->si as $si) {
            $strings[] = $this->extractText($si);
        }

        return $this->sharedStrings = $strings;
    }

    private function extractText(SimpleXMLElement $si): string
    {
        if (isset($si->t)) {
            return (string) $si->t;
        }

        $text = '';

        foreach ($si->r as $run) {
            $text .= (string) $run->t;
        }

        return $text;
    }

    /**
     * @return list<array{name: string, target: string}>
     */
    private function sheetManifest(): array
    {
        $workbookXml = file_get_contents("zip://{$this->xlsxPath}#xl/workbook.xml");
        $relsXml = file_get_contents("zip://{$this->xlsxPath}#xl/_rels/workbook.xml.rels");

        if ($workbookXml === false || $relsXml === false) {
            throw new RuntimeException("\"{$this->xlsxPath}\" does not look like a valid .xlsx file.");
        }

        $workbook = new SimpleXMLElement($workbookXml);
        $rels = new SimpleXMLElement($relsXml);

        $targetsById = [];

        foreach ($rels->Relationship as $relationship) {
            $targetsById[(string) $relationship['Id']] = (string) $relationship['Target'];
        }

        $manifest = [];
        $relNs = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

        foreach ($workbook->sheets->sheet as $sheet) {
            $rId = (string) $sheet->attributes($relNs)['id'];

            $manifest[] = [
                'name' => (string) $sheet['name'],
                'target' => $targetsById[$rId] ?? throw new RuntimeException("Sheet \"{$sheet['name']}\" has no matching relationship target."),
            ];
        }

        return $manifest;
    }

    /**
     * @param  list<array{name: string, target: string}>  $manifest
     * @return array{name: string, target: string}
     */
    private function findSheet(array $manifest, string $sheetName): array
    {
        foreach ($manifest as $sheet) {
            if ($sheet['name'] === $sheetName) {
                return $sheet;
            }
        }

        throw new RuntimeException("\"{$this->xlsxPath}\" has no sheet named \"{$sheetName}\".");
    }
}
