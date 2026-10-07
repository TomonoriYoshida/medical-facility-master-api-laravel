<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Supports the list API's open_at filter, which looks up the periods of
     * one day and time across every facility. It covers all the columns the
     * filter reads, so MySQL scans the matching day's entries of this index
     * instead of all 1.6 million rows (counting a nationwide open_at search
     * took ~0.3s locally before, ~0.09s after).
     */
    public function up(): void
    {
        Schema::table('medical_facility_opening_periods', function (Blueprint $table) {
            // The default name is over MySQL's 64-character limit.
            $table->index(['day', 'opens', 'closes', 'weeks', 'medical_facility_id'], 'medical_facility_opening_periods_day_opens_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('medical_facility_opening_periods', function (Blueprint $table) {
            $table->dropIndex('medical_facility_opening_periods_day_opens_index');
        });
    }
};
