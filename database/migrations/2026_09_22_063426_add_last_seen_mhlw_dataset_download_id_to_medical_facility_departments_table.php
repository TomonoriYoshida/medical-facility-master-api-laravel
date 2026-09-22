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
        Schema::table('medical_facility_departments', function (Blueprint $table) {
            $table->foreignId('last_seen_mhlw_dataset_download_id')
                ->nullable()
                ->constrained(
                    table: 'mhlw_dataset_downloads',
                    indexName: 'medical_facility_departments_last_seen_download_foreign',
                )
                ->nullOnDelete();

            $table->index(
                ['medical_facility_id', 'last_seen_mhlw_dataset_download_id'],
                'medical_facility_departments_reconcile_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('medical_facility_departments', function (Blueprint $table) {
            $table->dropIndex('medical_facility_departments_reconcile_index');
            $table->dropForeign('medical_facility_departments_last_seen_download_foreign');
            $table->dropColumn('last_seen_mhlw_dataset_download_id');
        });
    }
};
