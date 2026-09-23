<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('medical_facility_departments');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Irreversible: this table belonged to the discarded
        // 医療機能情報提供制度 CSV pipeline (time-banded department data with
        // no equivalent in the replacement 地方厚生局 data source).
    }
};
