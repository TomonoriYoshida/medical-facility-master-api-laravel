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
        Schema::dropIfExists('medical_facility_events');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Irreversible: recreated fresh (FK renamed mhlw_dataset_download_id
        // -> rhb_dataset_download_id) by create_medical_facility_events_table.
    }
};
