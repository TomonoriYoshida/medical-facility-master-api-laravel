<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Supports the event feed's detected_since filter, which the frontend
     * calls on every dashboard visit to count what appeared since the last.
     */
    public function up(): void
    {
        Schema::table('medical_facility_events', function (Blueprint $table) {
            $table->index(['origin', 'created_at'], 'medical_facility_events_origin_created_at_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('medical_facility_events', function (Blueprint $table) {
            $table->dropIndex('medical_facility_events_origin_created_at_index');
        });
    }
};
