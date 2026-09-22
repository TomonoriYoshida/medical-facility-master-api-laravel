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
        Schema::table('medical_facilities', function (Blueprint $table) {
            $table->foreignId('last_seen_mhlw_dataset_download_id')
                ->nullable()
                ->after('status')
                ->constrained(
                    table: 'mhlw_dataset_downloads',
                    indexName: 'medical_facilities_last_seen_download_foreign',
                )
                ->nullOnDelete();

            $table->index(
                ['institution_type', 'status', 'last_seen_mhlw_dataset_download_id'],
                'medical_facilities_reconcile_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('medical_facilities', function (Blueprint $table) {
            $table->dropIndex('medical_facilities_reconcile_index');
            $table->dropForeign('medical_facilities_last_seen_download_foreign');
            $table->dropColumn('last_seen_mhlw_dataset_download_id');
        });
    }
};
