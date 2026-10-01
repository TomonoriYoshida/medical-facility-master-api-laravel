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
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Writes every in-scope facility to gzipped CSV and JSON Lines files, one
 * pair per prefecture plus one for the whole scope, so that clients keeping
 * a local copy can load it in one download instead of paging the API
 * (~2,250 requests for the whole country).
 *
 * Each run writes a new set into its own exports/sets/{id} directory, and
 * only then points exports/manifest.json at it. The manifest is replaced by
 * a rename, which is atomic, so a request always sees one whole set and
 * never a moment with none. The replaced set is kept until the next run so a
 * download already in progress can finish. A run is skipped when neither
 * the data nor the scope changed since the current set, which keeps each
 * file's ETag stable.
 *
 * JSON Lines rows have exactly the shape of the API's facility resource.
 * CSV rows flatten it: codes and labels in separate columns, department
 * categories joined with "|", and the nested designation_history /
 * bed_counts as JSON text. A text that a spreadsheet would read as a
 * formula gets a leading quote (JSON Lines keep the original value).
 */
final class FacilityExporter
{
    private const string ROOT = 'exports';

    private const string SETS = 'exports/sets';

    private const string MANIFEST = 'exports/manifest.json';

    private const int CHUNK_SIZE = 1000;

    public const array CSV_COLUMNS = [
        'id', 'medical_institution_code', 'facility_code',
        'institution_type_code', 'institution_type', 'status_code', 'status', 'bureau_code', 'bureau',
        'name', 'prefecture_code', 'prefecture', 'municipality_code', 'municipality', 'postal_code', 'address',
        'latitude', 'longitude', 'geocode_level_code', 'geocode_level', 'phone_number',
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
        if (! Storage::disk('local')->exists(self::MANIFEST)) {
            return null;
        }

        return json_decode((string) Storage::disk('local')->get(self::MANIFEST), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * A manifest's entry for a file, so that only generated files can ever
     * be served. Takes the manifest the caller read, so the entry and the
     * path always come from the same set even if a new one is swapped in.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>|null
     */
    public function file(array $manifest, string $filename): ?array
    {
        foreach ($manifest['files'] ?? [] as $file) {
            if (is_array($file) && ($file['name'] ?? null) === $filename) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function pathOf(array $manifest, string $filename): string
    {
        return Storage::disk('local')->path(self::SETS."/{$manifest['set']}/{$filename}");
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

        $set = now()->format('YmdHis').'-'.Str::lower(Str::random(8));
        Storage::disk('local')->makeDirectory(self::SETS."/{$set}");

        $all = $this->openPair($set, 'all');
        $files = [];

        foreach ($this->scope->prefectures() as $prefecture) {
            $pair = $this->openPair($set, $prefecture->value);

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
            'set' => $set,
            'generated_at' => now()->toJSON(),
            'data_updated_at' => $dataUpdatedAt,
            'scope' => $scope,
            'files' => $files,
        ];

        $this->swapIn($manifest, previousSet: $current['set'] ?? null);

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
    private function openPair(string $set, string $suffix): array
    {
        $directory = self::SETS."/{$set}";
        $csv = new ExportFile(Storage::disk('local')->path("{$directory}/medical-facilities-{$suffix}.csv.gz"));
        $csv->writeCsv(self::CSV_COLUMNS);

        return [
            'csv' => $csv,
            'jsonl' => new ExportFile(Storage::disk('local')->path("{$directory}/medical-facilities-{$suffix}.jsonl.gz")),
        ];
    }

    /**
     * @param  array{csv: ExportFile, jsonl: ExportFile}  $pair
     * @param  array<string, mixed>  $row
     */
    private function writeRow(array $pair, MedicalFacility $facility, array $row): void
    {
        $pair['jsonl']->writeLine(json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $pair['csv']->writeCsv(array_map($this->neutralizeFormula(...), $this->csvRow($facility, $row)), countsAsRecord: true);
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
            $row['municipality']['code'] ?? null, $row['municipality']['label'] ?? null,
            $row['postal_code'], $row['address'],
            $row['location']['latitude'] ?? null, $row['location']['longitude'] ?? null,
            $row['location']['level']['code'] ?? null, $row['location']['level']['label'] ?? null,
            $row['phone_number'],
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
     * A text starting with = + - @ (or a tab / carriage return) would run as
     * a formula when the CSV is opened in a spreadsheet (CSV injection), so
     * it gets a leading quote, as the frontend's CSV does. Numbers and the
     * JSON columns never start with one of these.
     */
    private function neutralizeFormula(string|int|null $value): string|int|null
    {
        return is_string($value) && preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'{$value}" : $value;
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
     * Points the manifest at the new set with a rename (atomic on one
     * filesystem), then deletes every other set except the one it replaced,
     * which downloads already in progress may still be reading. Sets left
     * by a failed run and the directories of the earlier layout
     * (exports/current, previous, building) go too.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function swapIn(array $manifest, ?string $previousSet): void
    {
        $disk = Storage::disk('local');
        $temporary = self::MANIFEST.'.tmp';
        $disk->put($temporary, json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        if (! rename($disk->path($temporary), $disk->path(self::MANIFEST))) {
            throw new RuntimeException('Could not swap in the new export set.');
        }

        foreach ($disk->directories(self::SETS) as $directory) {
            if (! in_array(basename($directory), [$manifest['set'], $previousSet], true)) {
                $disk->deleteDirectory($directory);
            }
        }

        foreach ($disk->directories(self::ROOT) as $directory) {
            if ($directory !== self::SETS) {
                $disk->deleteDirectory($directory);
            }
        }
    }
}
