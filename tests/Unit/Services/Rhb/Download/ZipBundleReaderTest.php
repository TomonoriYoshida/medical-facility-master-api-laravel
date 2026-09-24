<?php

namespace Tests\Unit\Services\Rhb\Download;

use App\Services\Rhb\Download\ZipBundleReader;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

class ZipBundleReaderTest extends TestCase
{
    private string $zipPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->zipPath = tempnam(sys_get_temp_dir(), 'zip-bundle-reader-test-');
    }

    protected function tearDown(): void
    {
        @unlink($this->zipPath);

        parent::tearDown();
    }

    public function test_it_yields_each_entry_name_with_a_reader_for_its_contents(): void
    {
        $this->createZip(['a.xlsx' => 'first', 'b.xlsx' => 'second']);

        $contents = [];

        foreach ((new ZipBundleReader)->entries($this->zipPath, 1) as $entryName => $readContents) {
            $contents[$entryName] = $readContents();
        }

        $this->assertSame(['a.xlsx' => 'first', 'b.xlsx' => 'second'], $contents);
    }

    public function test_a_corrupted_entry_fails_loudly_instead_of_yielding_an_empty_body(): void
    {
        // Overwrite the first byte of the entry's deflate stream with a
        // block of the reserved type (BTYPE=11) so inflating it fails;
        // ZipArchive::getFromIndex() then returns "" rather than false. The
        // compressed data starts right after the 30-byte local file header
        // and the entry name.
        $this->createZip(['a.xlsx' => str_repeat('original-body ', 50)]);
        $bytes = file_get_contents($this->zipPath);
        $bytes[30 + strlen('a.xlsx')] = "\xFF";
        file_put_contents($this->zipPath, $bytes);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to read entry "a.xlsx"');

        foreach ((new ZipBundleReader)->entries($this->zipPath, 1) as $readContents) {
            $readContents();
        }
    }

    public function test_a_file_that_is_not_a_zip_is_rejected(): void
    {
        file_put_contents($this->zipPath, 'not a zip');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to open zip');

        iterator_to_array((new ZipBundleReader)->entries($this->zipPath, 1));
    }

    /**
     * @param  array<string, string>  $entries
     */
    private function createZip(array $entries): void
    {
        $zip = new ZipArchive;
        $zip->open($this->zipPath, ZipArchive::OVERWRITE);

        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();
    }
}
