<?php

use App\Enums\MedicalFacilityEventOrigin;
use App\Enums\MedicalFacilityEventType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds origin (App\Enums\MedicalFacilityEventOrigin) and classifies the
     * events recorded before it existed. Events from each bureau + category's
     * earliest publication are the baseline: its Created events are the
     * initial load, and any other event within that same publication can only
     * come from re-importing it. Everything later stays Detected, since past
     * --force runs on later publications cannot be told apart anymore.
     */
    public function up(): void
    {
        Schema::table('medical_facility_events', function (Blueprint $table) {
            $table->unsignedTinyInteger('origin')
                ->default(MedicalFacilityEventOrigin::Detected->value)
                ->after('event_type');
            $table->index(['origin', 'event_type', 'occurred_on'], 'medical_facility_events_origin_type_date_index');
        });

        DB::statement(<<<'SQL'
            UPDATE medical_facility_events e
            JOIN rhb_dataset_downloads d ON d.id = e.rhb_dataset_download_id
            JOIN (
                SELECT bureau_code, category, MIN(published_on) AS first_published_on
                FROM rhb_dataset_downloads
                GROUP BY bureau_code, category
            ) f ON f.bureau_code = d.bureau_code
                AND f.category = d.category
                AND f.first_published_on = d.published_on
            SET e.origin = IF(e.event_type = ?, ?, ?)
            SQL, [
            MedicalFacilityEventType::Created->value,
            MedicalFacilityEventOrigin::Baseline->value,
            MedicalFacilityEventOrigin::Reprocessed->value,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('medical_facility_events', function (Blueprint $table) {
            $table->dropIndex('medical_facility_events_origin_type_date_index');
            $table->dropColumn('origin');
        });
    }
};
