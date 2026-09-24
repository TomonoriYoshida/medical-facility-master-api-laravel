<?php

namespace Tests\Unit\Services\Rhb\Download;

use App\Services\Rhb\Download\ZipBundleReader;
use ErrorException;
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
        $this->createCorruptedZip('a.xlsx');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to read entry "a.xlsx"');

        foreach ((new ZipBundleReader)->entries($this->zipPath, 1) as $readContents) {
            $readContents();
        }
    }

    public function test_a_corrupted_entry_keeps_its_context_when_warnings_are_converted_to_exceptions(): void
    {
        $this->createCorruptedZip('a.xlsx');
        set_error_handler(function (int $level, string $message): never {
            throw new ErrorException($message, 0, $level);
        });

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Unable to read entry "a.xlsx" in zip "'.$this->zipPath.'" (download #7).');

            foreach ((new ZipBundleReader)->entries($this->zipPath, 7) as $readContents) {
                $readContents();
            }
        } finally {
            restore_error_handler();
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

    /**
     * Overwrites the first byte of the entry's deflate stream with a block
     * of the reserved type (BTYPE=11) so inflating it fails;
     * ZipArchive::getFromIndex() then returns "" rather than false. The
     * compressed data starts right after the 30-byte local file header and
     * the entry name.
     */
    private function createCorruptedZip(string $entryName): void
    {
        $this->createZip([$entryName => str_repeat('original-body ', 50)]);
        $bytes = (string) file_get_contents($this->zipPath);
        $bytes[30 + strlen($entryName)] = "\xFF";
        file_put_contents($this->zipPath, $bytes);
    }
}
