<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('rhb_dataset_downloads', function (Blueprint $table) {
            // Set once a download has been fully imported with no failed
            // rows, so the daily rhb:import can skip unchanged datasets.
            $table->dateTime('imported_at')->nullable()->after('downloaded_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rhb_dataset_downloads', function (Blueprint $table) {
            $table->dropColumn('imported_at');
        });
    }
};
