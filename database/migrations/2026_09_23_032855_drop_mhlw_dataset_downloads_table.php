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
        Schema::dropIfExists('mhlw_dataset_downloads');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Irreversible: replaced by rhb_dataset_downloads, which tracks
        // bureau/category/prefecture dimensions the old dataset_key-based
        // shape had no room for.
    }
};
