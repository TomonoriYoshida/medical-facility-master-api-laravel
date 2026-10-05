<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Opening hours from the MHLW 医療情報ネット open data, one row per
     * facility it lists hours for, keyed by its ID
     * (medical_info_net_locations.source_id). Departments with the same hours
     * are grouped into one schedule (MedicalInfoNetHours). Replaced as a
     * whole together with medical_info_net_locations on each import.
     */
    public function up(): void
    {
        Schema::create('medical_info_net_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('source_id', 20)->unique();
            // list<{departments: list<string>, slots: list<{number, days: list<{day, opens, closes, reception_opens, reception_closes}>}>}>
            $table->json('schedules');
            $table->datetimes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('medical_info_net_schedules');
    }
};
