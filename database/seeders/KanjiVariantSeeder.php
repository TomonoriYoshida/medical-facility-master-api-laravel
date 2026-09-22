<?php

namespace Database\Seeders;

use App\Models\KanjiVariant;
use Illuminate\Database\Seeder;
use SplFileObject;

class KanjiVariantSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $file = new SplFileObject(database_path('seeders/data/itaiji-mapping.csv'));
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD);

        $header = $file->fgetcsv();
        $now = now();
        $rows = [];

        // A plain foreach() would call SplFileObject's implicit rewind(),
        // undoing the fgetcsv() header skip above -- advance manually instead.
        while (! $file->eof()) {
            $line = $file->fgetcsv();

            if ($line === false || $line === null || $line === [null]) {
                continue;
            }

            $row = array_combine($header, $line);

            $rows[] = [
                'variant_character' => $row['variant'],
                'canonical_character' => $row['canonical'],
                'source' => $row['source'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        KanjiVariant::query()->insert($rows);
    }
}
