<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When each facility is open, for the list API's open_at filter: the
     * 医療情報ネット hours of its match (medical_facilities.medical_info_net_id)
     * as one row per day and time range, merged across departments
     * (App\Services\MedicalInfoNet\OpeningPeriods). Rebuilt by
     * facilities:assign-opening-hours.
     */
    public function up(): void
    {
        Schema::create('medical_facility_opening_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medical_facility_id')->constrained()->cascadeOnDelete();
            // 1 (Monday) .. 7 (Sunday) as ISO 8601, 8 for public holidays.
            $table->unsignedTinyInteger('day');
            $table->time('opens');
            // Up to 24:00:00, for a range that runs to midnight.
            $table->time('closes');
            // The weeks of the month (bit 0 = 1st .. bit 4 = 5th) the range
            // applies to: 31 unless the facility is closed on some of them
            // (第2水曜休診 leaves Wednesday's ranges without bit 1).
            $table->unsignedTinyInteger('weeks')->default(31);

            // The default name is over MySQL's 64-character limit.
            $table->index(['medical_facility_id', 'day', 'opens'], 'medical_facility_opening_periods_facility_day_opens_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('medical_facility_opening_periods');
    }
};
