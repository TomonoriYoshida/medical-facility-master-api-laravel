<?php

namespace App\Console\Commands;

use App\Models\MedicalFacility;
use App\Models\MedicalFacilityEvent;
use App\Models\RhbDatasetDownload;
use App\Services\Rhb\RhbScope;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes what RHB_PREFECTURES / RHB_CATEGORIES no longer cover: narrowing
 * the scope only stops crawling and importing, so facilities outside it
 * would otherwise stay in the API frozen at their last import. Removal is
 * this explicit, confirmed step rather than a side effect of editing .env.
 *
 * Facilities go together with their events (medical_facility_events
 * restricts deleting a facility that still has history). Downloads go with
 * their files, including the per-download extraction directory
 * (rhb/{bureau}/extracted/{id}) every bundle expander writes to.
 */
#[Signature('rhb:prune
    {--dry-run : 削除対象の件数を表示するだけで、削除しない}
    {--force : 確認せずに削除する}')]
#[Description('Delete facilities, events and downloads outside the configured scope (RHB_PREFECTURES / RHB_CATEGORIES)')]
class PruneOutOfScopeRhbData extends Command
{
    private const int CHUNK_SIZE = 1000;

    public function handle(RhbScope $scope): int
    {
        $facilities = $this->outOfScopeFacilities($scope);
        $facilityCount = $facilities->count();
        $eventCount = MedicalFacilityEvent::query()->whereIn('medical_facility_id', $this->outOfScopeFacilities($scope)->select('id'))->count();
        $downloads = $this->outOfScopeDownloads($scope);

        $this->components->twoColumnDetail('範囲外の施設', (string) $facilityCount);
        $this->components->twoColumnDetail('その変更履歴', (string) $eventCount);
        $this->components->twoColumnDetail('範囲外のダウンロード', (string) $downloads->count());

        if ($facilityCount === 0 && $downloads->isEmpty()) {
            $this->components->info('範囲外のデータはありません。');

            return Command::SUCCESS;
        }

        if ($this->option('dry-run')) {
            return Command::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('これらを削除します。元に戻せません。続けますか？')) {
            $this->components->warn('中止しました。');

            return Command::FAILURE;
        }

        $facilities->chunkById(self::CHUNK_SIZE, function (Collection $chunk): void {
            DB::transaction(function () use ($chunk): void {
                MedicalFacilityEvent::query()->whereIn('medical_facility_id', $chunk->modelKeys())->delete();
                MedicalFacility::query()->whereKey($chunk->modelKeys())->delete();
            });
        });

        $this->deleteDownloads($downloads);

        $this->components->info('範囲外のデータを削除しました。');

        return Command::SUCCESS;
    }

    /**
     * @return Builder<MedicalFacility>
     */
    private function outOfScopeFacilities(RhbScope $scope): Builder
    {
        return MedicalFacility::query()->where(fn (Builder $query) => $query
            ->whereNotIn('prefecture_code', array_map(fn ($prefecture): string => $prefecture->value, $scope->prefectures()))
            ->orWhereNotIn('institution_type', $scope->institutionTypes()));
    }

    /**
     * A download is out of scope when its category is, or when none of the
     * prefectures it covers are (a whole bureau, or one Kyushu prefecture).
     * A bundle still covering an in-scope prefecture is kept.
     *
     * @return Collection<int, RhbDatasetDownload>
     */
    private function outOfScopeDownloads(RhbScope $scope): Collection
    {
        return RhbDatasetDownload::all()->filter(fn (RhbDatasetDownload $download): bool => ! $scope->includesCategory($download->category)
            || ! collect($download->prefecture_codes)->contains(fn (string $code): bool => $scope->includesPrefecture($code)));
    }

    /**
     * @param  Collection<int, RhbDatasetDownload>  $downloads
     */
    private function deleteDownloads(Collection $downloads): void
    {
        $bureauKeys = [];

        foreach (config()->array('rhb.bureaus') as $key => $meta) {
            $bureauKeys[$meta['bureau']->value] = $key;
        }

        RhbDatasetDownload::query()->whereKey($downloads->modelKeys())->delete();

        foreach ($downloads as $download) {
            Storage::disk('local')->deleteDirectory("rhb/{$bureauKeys[$download->bureau_code->value]}/extracted/{$download->id}");

            // Some bureaus keep one filename across monthly versions, so
            // another (in-scope) row may still point at the same file.
            if (! RhbDatasetDownload::query()->where('local_path', $download->local_path)->exists()) {
                Storage::disk('local')->delete($download->local_path);
            }
        }
    }
}
