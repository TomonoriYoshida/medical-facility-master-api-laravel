<?php

namespace App\Services\MedicalInfoNet;

use App\Enums\InstitutionType;
use App\Services\Http\OpenDataHttp;
use Generator;
use Illuminate\Support\Carbon;
use RuntimeException;
use ZipArchive;

/**
 * Finds the latest 医療情報ネット publication on the MHLW index page,
 * downloads its facility files and reads their rows.
 */
final class MedicalInfoNetClient
{
    private const string DIRECTORY = 'medical-info-net';

    public function __construct(
        private readonly OpenDataHttp $http,
    ) {}

    /**
     * The newest date for which every configured dataset is published, with
     * each dataset's file URL. Older publications stay listed on the page
     * (some without ".csv" in the name), so the dates are compared.
     *
     * @return array{published_on: Carbon, files: array<string, array{url: string, institution_type: InstitutionType}>}
     */
    public function latest(): array
    {
        $html = $this->http->get(config()->string('medical_info_net.index_url'))->body();
        /** @var array<string, InstitutionType> $datasets */
        $datasets = config('medical_info_net.datasets');
        $urlsByDate = [];

        preg_match_all('#href="(/content/\d+/([0-9a-z_-]+?)_(\d{8})(?:\.csv)?\.zip)"#', $html, $matches, PREG_SET_ORDER);

        foreach ($matches as [, $path, $dataset, $date]) {
            if (isset($datasets[$dataset])) {
                $urlsByDate[$date][$dataset] ??= rtrim(config()->string('medical_info_net.base_url'), '/').$path;
            }
        }

        krsort($urlsByDate);

        foreach ($urlsByDate as $date => $urls) {
            if (count($urls) === count($datasets)) {
                return [
                    'published_on' => Carbon::createFromFormat('!Ymd', (string) $date)
                        ?? throw new RuntimeException("Unreadable publication date \"{$date}\"."),
                    'files' => array_map(
                        fn (string $dataset): array => ['url' => $urls[$dataset], 'institution_type' => $datasets[$dataset]],
                        array_combine(array_keys($datasets), array_keys($datasets)),
                    ),
                ];
            }
        }

        throw new RuntimeException('No 医療情報ネット publication with every facility file was found on '.config()->string('medical_info_net.index_url').'.');
    }

    /**
     * Downloads a file into the local disk and returns its path.
     */
    public function download(string $url): string
    {
        return $this->http->download($url, self::DIRECTORY, timeout: 300);
    }

    /**
     * The rows of a downloaded zip's CSV (UTF-8 with BOM) as column => value.
     *
     * @return Generator<int, array<string, string>>
     */
    public function rows(string $zipPath): Generator
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException("Unable to open zip \"{$zipPath}\".");
        }

        try {
            $stream = $zip->getStream((string) $zip->getNameIndex(0));

            if ($stream === false) {
                throw new RuntimeException("Unable to read the CSV in \"{$zipPath}\".");
            }

            $header = fgetcsv($stream, escape: '');

            if ($header === false) {
                return;
            }

            $header = array_map(fn (?string $column): string => (string) $column, $header);
            $header[0] = (string) preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);

            while (($line = fgetcsv($stream, escape: '')) !== false) {
                if (count($line) === count($header)) {
                    yield array_combine($header, array_map(fn (?string $value): string => (string) $value, $line));
                }
            }

            fclose($stream);
        } finally {
            $zip->close();
        }
    }
}
