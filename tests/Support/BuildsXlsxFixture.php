<?php

namespace Tests\Support;

use ZipArchive;

/**
 * Builds minimal, real .xlsx files for tests (shared-string cells only,
 * matching every cell type observed in real 地方厚生局 source files) so
 * App\Services\Rhb\Import\RhbXlsxReader can be exercised without needing a
 * spreadsheet library as a test dependency.
 */
trait BuildsXlsxFixture
{
    /**
     * @var list<string>
     */
    private array $xlsxTempPaths = [];

    /**
     * @param  list<list<string>>  $rows  0-indexed rows of 0-indexed column values
     */
    private function createXlsx(array $rows, string $sheetName = 'Sheet1'): string
    {
        return $this->createMultiSheetXlsx([$sheetName => $rows]);
    }

    /**
     * @param  array<string, list<list<string>>>  $sheets  sheet name => 0-indexed rows of 0-indexed column values
     */
    private function createMultiSheetXlsx(array $sheets): string
    {
        $strings = [];
        $stringIndex = [];

        $indexOf = function (string $value) use (&$strings, &$stringIndex): int {
            if (! isset($stringIndex[$value])) {
                $stringIndex[$value] = count($strings);
                $strings[] = $value;
            }

            return $stringIndex[$value];
        };

        $sheetEntries = [];
        $sheetElements = '';
        $relationshipElements = '';
        $sheetNumber = 0;

        foreach ($sheets as $sheetName => $rows) {
            $sheetNumber++;
            $rowsXml = '';

            foreach ($rows as $rowIndex => $row) {
                $r = $rowIndex + 1;
                $cellsXml = '';

                foreach ($row as $colIndex => $value) {
                    if ($value === null || $value === '') {
                        continue;
                    }

                    $colLetter = $this->columnLetter($colIndex);
                    $idx = $indexOf((string) $value);
                    $cellsXml .= "<c r=\"{$colLetter}{$r}\" t=\"s\"><v>{$idx}</v></c>";
                }

                $rowsXml .= "<row r=\"{$r}\">{$cellsXml}</row>";
            }

            $sheetEntries["worksheets/sheet{$sheetNumber}.xml"] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                ."<sheetData>{$rowsXml}</sheetData></worksheet>";

            $sheetElements .= '<sheet name="'.htmlspecialchars($sheetName, ENT_XML1).'" sheetId="'.$sheetNumber.'" r:id="rId'.$sheetNumber.'"/>';
            $relationshipElements .= '<Relationship Id="rId'.$sheetNumber.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$sheetNumber.'.xml"/>';
        }

        $sstXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.count($strings).'" uniqueCount="'.count($strings).'">';

        foreach ($strings as $s) {
            $escaped = htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $sstXml .= "<si><t xml:space=\"preserve\">{$escaped}</t></si>";
        }

        $sstXml .= '</sst>';

        $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            ."<sheets>{$sheetElements}</sheets></workbook>";

        $workbookRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            ."{$relationshipElements}"
            .'</Relationships>';

        $rootRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';

        $sheetOverridesXml = '';
        foreach (array_keys($sheetEntries) as $target) {
            $sheetOverridesXml .= '<Override PartName="/xl/'.$target.'" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        $contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            ."{$sheetOverridesXml}"
            .'<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            .'</Types>';

        $path = tempnam(sys_get_temp_dir(), 'rhb_xlsx_test_').'.xlsx';

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', $contentTypesXml);
        $zip->addFromString('_rels/.rels', $rootRelsXml);
        $zip->addFromString('xl/workbook.xml', $workbookXml);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRelsXml);
        $zip->addFromString('xl/sharedStrings.xml', $sstXml);

        foreach ($sheetEntries as $target => $xml) {
            $zip->addFromString("xl/{$target}", $xml);
        }

        $zip->close();

        $this->xlsxTempPaths[] = $path;

        return $path;
    }

    /**
     * @param  array<string, list<list<string>>>  $entries  zip entry filename => 0-indexed rows of 0-indexed column values
     */
    private function createZipOfXlsxFiles(array $entries): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'rhb_xlsx_zip_test_').'.zip';

        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($entries as $entryName => $rows) {
            $zip->addFile($this->createXlsx($rows), $entryName);
        }

        $zip->close();

        $this->xlsxTempPaths[] = $zipPath;

        return $zipPath;
    }

    private function columnLetter(int $index): string
    {
        $letter = '';
        $index++;

        while ($index > 0) {
            $index--;
            $letter = chr(65 + ($index % 26)).$letter;
            $index = intdiv($index, 26);
        }

        return $letter;
    }

    private function cleanUpXlsxFixtures(): void
    {
        foreach ($this->xlsxTempPaths as $path) {
            @unlink($path);
        }

        $this->xlsxTempPaths = [];
    }
}
