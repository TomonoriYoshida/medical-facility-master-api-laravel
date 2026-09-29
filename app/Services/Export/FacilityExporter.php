<?php

namespace App\Services\Export;

use App\Enums\DepartmentBaseCategory;
use App\Enums\Prefecture;
use App\Enums\RhbCategory;
use App\Http\Resources\Api\V1\MedicalFacilityResource;
use App\Models\MedicalFacility;
use App\Services\Rhb\RhbScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Writes every in-scope facility to gzipped CSV and JSON Lines files, one
 * pair per prefecture plus one for the whole scope, so that clients keeping
 * a local copy can load it in one download instead of paging the API
 * (~2,250 requests for the whole country).
 *
 * Files are built in exports/building and only then swapped in for
 * exports/current, so a download never sees a half-written set; the
 * replaced set is kept as exports/previous so a download already in
 * progress can finish. A run is skipped when neither the data nor the scope
 * changed since the current set, which keeps each file's ETag stable.
 *
 * JSON Lines rows have exactly the shape of the API's facility resource.
 * CSV rows flatten it: codes and labels in separate columns, department
 * categories joined with "|", and the nested designation_history /
 * bed_counts as JSON text.
 */
final class FacilityExporter
{
    private const string CURRENT = 'exports/current';

    private const string BUILDING = 'exports/building';

    private const string PREVIOUS = 'exports/previous';

    private const string MANIFEST = 'manifest.json';

    private const int CHUNK_SIZE = 1000;

    public const array CSV_COLUMNS = [
        'id', 'medical_institution_code', 'facility_code',
        'institution_type_code', 'institution_type', 'status_code', 'status', 'bureau_code', 'bureau',
        'name', 'prefecture_code', 'prefecture', 'postal_code', 'address', 'phone_number',
        'designated_on', 'designation_history', 'bed_counts',
        'department_category_codes', 'department_categories',
        'created_at', 'updated_at',
    ];

    public function __construct(
        private readonly RhbScope $scope,
    ) {}

    /**
     * The current set's manifest, or null before the first export.
     *
     * @return array<string, mixed>|null
     */
    public function manifest(): ?array
    {
        $path = self::CURRENT.'/'.self::MANIFEST;

        if (! Storage::disk('local')->exists($path)) {
            return null;
        }

        return json_decode((string) Storage::disk('local')->get($path), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * The current manifest's entry for a file, so that only generated files
     * can ever be served.
     *
     * @return array<string, mixed>|null
     */
    public function file(string $filename): ?array
    {
        foreach ($this->manifest()['files'] ?? [] as $file) {
            if (is_array($file) && ($file['name'] ?? null) === $filename) {
                return $file;
            }
        }

        return null;
    }

    public function pathOf(string $filename): ?string
    {
        return $this->file($filename) !== null ? Storage::disk('local')->path(self::CURRENT.'/'.$filename) : null;
    }

    /**
     * @return array<string, mixed>|null the new manifest, or null when skipped
     */
    public function export(bool $force = false): ?array
    {
        $scope = [
            'prefectures' => array_map(fn (Prefecture $prefecture): string => $prefecture->value, $this->scope->prefectures()),
            'categories' => array_map(fn (RhbCategory $category): string => $category->key(), $this->scope->categories()),
        ];
        $lastUpdatedAt = $this->facilities()->max('updated_at');
        $dataUpdatedAt = $lastUpdatedAt !== null ? Carbon::parse($lastUpdatedAt)->toJSON() : null;

        $current = $this->manifest();

        if (! $force && $current !== null && $current['data_updated_at'] === $dataUpdatedAt && $current['scope'] === $scope) {
            return null;
        }

        $disk = Storage::disk('local');
        $disk->deleteDirectory(self::BUILDING);
        $disk->makeDirectory(self::BUILDING);

        $all = $this->openPair('all');
        $files = [];

        foreach ($this->scope->prefectures() as $prefecture) {
            $pair = $this->openPair($prefecture->value);

            $this->facilities()
                ->where('prefecture_code', $prefecture->value)
                ->chunkById(self::CHUNK_SIZE, function (Collection $facilities) use ($pair, $all): void {
                    foreach ($facilities as $facility) {
                        $row = (new MedicalFacilityResource($facility))->resolve();
                        $this->writeRow($pair, $facility, $row);
                        $this->writeRow($all, $facility, $row);
                    }
                });

            array_push($files, ...$this->closePair($pair, $prefecture));
        }

        array_push($files, ...$this->closePair($all, null));

        $manifest = [
            'generated_at' => now()->toJSON(),
            'data_updated_at' => $dataUpdatedAt,
            'scope' => $scope,
            'files' => $files,
        ];

        $disk->put(self::BUILDING.'/'.self::MANIFEST, json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $this->swapIn();

        return $manifest;
    }

    /**
     * @return Builder<MedicalFacility>
     */
    private function facilities(): Builder
    {
        return MedicalFacility::query()
            ->whereIn('prefecture_code', array_map(fn (Prefecture $prefecture): string => $prefecture->value, $this->scope->prefectures()))
            ->whereIn('institution_type', $this->scope->institutionTypes());
    }

    /**
     * @return array{csv: ExportFile, jsonl: ExportFile}
     */
    private function openPair(string $suffix): array
    {
        $csv = new ExportFile(Storage::disk('local')->path(self::BUILDING."/medical-facilities-{$suffix}.csv.gz"));
        $csv->writeCsv(self::CSV_COLUMNS);

        return [
            'csv' => $csv,
            'jsonl' => new ExportFile(Storage::disk('local')->path(self::BUILDING."/medical-facilities-{$suffix}.jsonl.gz")),
        ];
    }

    /**
     * @param  array{csv: ExportFile, jsonl: ExportFile}  $pair
     * @param  array<string, mixed>  $row
     */
    private function writeRow(array $pair, MedicalFacility $facility, array $row): void
    {
        $pair['jsonl']->writeLine(json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $pair['csv']->writeCsv($this->csvRow($facility, $row), countsAsRecord: true);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string|int|null>
     */
    private function csvRow(MedicalFacility $facility, array $row): array
    {
        $departments = $facility->department_categories ?? collect();

        return [
            $row['id'], $row['medical_institution_code'], $row['facility_code'],
            $row['institution_type']['code'], $row['institution_type']['label'],
            $row['status']['code'], $row['status']['label'],
            $row['bureau']['code'], $row['bureau']['label'],
            $row['name'], $row['prefecture_code'], $row['prefecture']['label'],
            $row['postal_code'], $row['address'], $row['phone_number'],
            $row['designated_on'],
            json_encode($row['designation_history'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            $row['bed_counts'] === null ? null : json_encode($row['bed_counts'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            $departments->map(fn (DepartmentBaseCategory $category): int => $category->value)->implode('|'),
            $departments->map(fn (DepartmentBaseCategory $category): string => $category->label())->implode('|'),
            $row['created_at']?->toJSON(),
            $row['updated_at']?->toJSON(),
        ];
    }

    /**
     * @param  array{csv: ExportFile, jsonl: ExportFile}  $pair
     * @return list<array<string, mixed>>
     */
    private function closePair(array $pair, ?Prefecture $prefecture): array
    {
        $entries = [];

        foreach ($pair as $format => $file) {
            $file->close();

            $entries[] = [
                'name' => basename($file->path),
                'format' => $format,
                'prefecture' => $prefecture === null ? null : ['code' => $prefecture->value, 'label' => $prefecture->label()],
                'records' => $file->records(),
                'size' => filesize($file->path),
                'sha256' => hash_file('sha256', $file->path),
            ];
        }

        return $entries;
    }

    /**
     * Directory renames are atomic on one filesystem, and a download that
     * already opened a file keeps reading it after its directory moves.
     */
    private function swapIn(): void
    {
        $disk = Storage::disk('local');
        $disk->deleteDirectory(self::PREVIOUS);

        if ($disk->exists(self::CURRENT) && ! rename($disk->path(self::CURRENT), $disk->path(self::PREVIOUS))) {
            throw new RuntimeException('Could not move the current export set aside.');
        }

        if (! rename($disk->path(self::BUILDING), $disk->path(self::CURRENT))) {
            throw new RuntimeException('Could not swap in the new export set.');
        }
    }
}
