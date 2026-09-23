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
        $strings = [];
        $stringIndex = [];

        $indexOf = function (string $value) use (&$strings, &$stringIndex): int {
            if (! isset($stringIndex[$value])) {
                $stringIndex[$value] = count($strings);
                $strings[] = $value;
            }

            return $stringIndex[$value];
        };

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

        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            ."<sheetData>{$rowsXml}</sheetData></worksheet>";

        $sstXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.count($strings).'" uniqueCount="'.count($strings).'">';

        foreach ($strings as $s) {
            $escaped = htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $sstXml .= "<si><t xml:space=\"preserve\">{$escaped}</t></si>";
        }

        $sstXml .= '</sst>';

        $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.htmlspecialchars($sheetName, ENT_XML1).'" sheetId="1" r:id="rId1"/></sheets></workbook>';

        $workbookRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'</Relationships>';

        $rootRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';

        $contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
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
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->close();

        $this->xlsxTempPaths[] = $path;

        return $path;
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
