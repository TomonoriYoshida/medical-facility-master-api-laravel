<?php

namespace Database\Seeders;

use App\Models\Municipality;
use Illuminate\Database\Seeder;
use SplFileObject;

/**
 * Loads database/seeders/data/municipalities.csv, derived from the Digital
 * Agency's Address Base Registry 市区町村マスター (mt_city, 廃止済みを除く).
 */
class MunicipalitySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $file = new SplFileObject(database_path('seeders/data/municipalities.csv'));
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD);

        $header = $file->fgetcsv();
        $now = now();
        $rows = [];

        // See KanjiVariantSeeder: a foreach() would rewind past the header.
        while (! $file->eof()) {
            $line = $file->fgetcsv();

            if ($line === false || $line === null || $line === [null]) {
                continue;
            }

            $rows[] = [...array_combine($header, $line), 'created_at' => $now, 'updated_at' => $now];
        }

        // upsert so the seeder can be re-run after the CSV is refreshed; follow
        // it with `facilities:assign-municipalities` to apply the change.
        foreach (array_chunk($rows, 500) as $chunk) {
            Municipality::query()->upsert(
                $chunk,
                uniqueBy: ['code'],
                update: ['prefecture_code', 'name', 'name_kana', 'updated_at'],
            );
        }
    }
}
