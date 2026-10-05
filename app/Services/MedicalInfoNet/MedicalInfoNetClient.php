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
 * downloads its facility and hours files and reads their rows.
 */
final class MedicalInfoNetClient
{
    private const string DIRECTORY = 'medical-info-net';

    public function __construct(
        private readonly OpenDataHttp $http,
    ) {}

    /**
     * The newest date for which every configured dataset (facilities and
     * hours) is published, with each dataset's file URL. Older publications
     * stay listed on the page (some without ".csv" in the name), so the
     * dates are compared.
     *
     * @return array{published_on: Carbon, files: array<string, array{url: string, institution_type: InstitutionType}>, hours_files: array<string, array{url: string, institution_type: InstitutionType}>}
     */
    public function latest(): array
    {
        $html = $this->http->get(config()->string('medical_info_net.index_url'))->body();
        /** @var array<string, InstitutionType> $facilityDatasets */
        $facilityDatasets = config('medical_info_net.datasets');
        /** @var array<string, InstitutionType> $hoursDatasets */
        $hoursDatasets = config('medical_info_net.hours_datasets');
        $datasets = [...$facilityDatasets, ...$hoursDatasets];
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
                $files = fn (array $datasets): array => array_map(
                    fn (InstitutionType $institutionType, string $dataset): array => ['url' => $urls[$dataset], 'institution_type' => $institutionType],
                    $datasets,
                    array_keys($datasets),
                );

                return [
                    'published_on' => Carbon::createFromFormat('!Ymd', (string) $date)
                        ?? throw new RuntimeException("Unreadable publication date \"{$date}\"."),
                    'files' => array_combine(array_keys($facilityDatasets), $files($facilityDatasets)),
                    'hours_files' => array_combine(array_keys($hoursDatasets), $files($hoursDatasets)),
                ];
            }
        }

        throw new RuntimeException('No 医療情報ネット publication with every facility and hours file was found on '.config()->string('medical_info_net.index_url').'.');
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
            // The BOM comes before the first column's opening quote, so
            // fgetcsv() keeps that column's quotes as part of its name.
            $header[0] = trim((string) preg_replace('/^\xEF\xBB\xBF/', '', $header[0]), '"');

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
