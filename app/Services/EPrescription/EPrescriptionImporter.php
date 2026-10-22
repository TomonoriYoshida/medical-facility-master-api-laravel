<?php

namespace App\Services\EPrescription;

use App\Models\MedicalFacility;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sets each facility's 電子処方箋 start dates from the MHLW's list, matched
 * by the 10-digit 医療機関コード; a facility no longer listed has them
 * cleared. Only the facilities whose dates change are written, and their
 * updated_at moves: the dates are part of the facility the API serves, so
 * clients syncing with updated_since (and the bulk download) pick them up.
 */
final class EPrescriptionImporter
{
    public const string AS_OF_CACHE_KEY = 'e-prescriptions:as-of';

    private const int CHUNK_SIZE = 1000;

    public function __construct(
        private readonly EPrescriptionClient $client,
    ) {}

    /**
     * @return array{as_of: Carbon, listed: int, matched: int, changed: int}|null null when that list is already imported, unless $force
     */
    public function import(bool $force = false): ?array
    {
        $latest = $this->client->latest();
        $current = Cache::get(self::AS_OF_CACHE_KEY);

        if (! $force && is_string($current) && $latest['as_of']->lte(Carbon::parse($current))) {
            return null;
        }

        // Read fully before any facility is touched, so a list that fails to
        // parse leaves the previous dates in place.
        $dates = iterator_to_array($this->client->facilities($this->client->download($latest['url'])));
        $minimum = config()->integer('e_prescriptions.minimum_facilities');

        if (count($dates) < $minimum) {
            throw new RuntimeException('Only '.count($dates)." facilities were read from {$latest['url']} (expected at least {$minimum}); the previous dates were kept.");
        }

        $matched = 0;
        $changed = 0;

        // One transaction, so a failure part way leaves every facility on
        // the previous list rather than a mix of two.
        DB::transaction(function () use ($dates, &$matched, &$changed): void {
            MedicalFacility::query()
                ->toBase()
                ->select(['id', 'medical_institution_code', 'e_prescription_started_on', 'e_prescription_refill_started_on'])
                ->chunkById(self::CHUNK_SIZE, function (Collection $facilities) use ($dates, &$matched, &$changed): void {
                    $changes = [];

                    foreach ($facilities as $facility) {
                        $listed = $dates[$facility->medical_institution_code] ?? null;
                        $matched += $listed === null ? 0 : 1;
                        [$started, $refill] = $listed === null ? [null, null] : explode('|', $listed);
                        $new = [$started, $refill === '' ? null : $refill];

                        if ($new !== [$facility->e_prescription_started_on, $facility->e_prescription_refill_started_on]) {
                            $changes[$facility->id] = $new;
                        }
                    }

                    $this->update($changes);
                    $changed += count($changes);
                });
        });

        Cache::forever(self::AS_OF_CACHE_KEY, $latest['as_of']->toDateString());

        return ['as_of' => $latest['as_of'], 'listed' => count($dates), 'matched' => $matched, 'changed' => $changed];
    }

    /**
     * One UPDATE for a chunk's changed facilities.
     *
     * @param  array<int, array{0: string|null, 1: string|null}>  $changes  facility id => [started_on, refill_started_on]
     */
    private function update(array $changes): void
    {
        if ($changes === []) {
            return;
        }

        $cases = implode(' ', array_fill(0, count($changes), 'WHEN ? THEN ?'));
        $placeholders = implode(',', array_fill(0, count($changes), '?'));
        $startedOn = [];
        $refillStartedOn = [];

        foreach ($changes as $id => [$started, $refill]) {
            array_push($startedOn, $id, $started);
            array_push($refillStartedOn, $id, $refill);
        }

        DB::update(
            "UPDATE medical_facilities SET e_prescription_started_on = CASE id {$cases} END, e_prescription_refill_started_on = CASE id {$cases} END, updated_at = ? WHERE id IN ({$placeholders})",
            [...$startedOn, ...$refillStartedOn, now(), ...array_keys($changes)],
        );
    }
}
