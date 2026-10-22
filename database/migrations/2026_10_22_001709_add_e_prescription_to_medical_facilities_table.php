<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When each facility started using 電子処方箋 (and refill prescriptions
     * as 電子処方箋), from the MHLW's weekly list (e-prescriptions:import).
     * The list filter (e_prescription) reads the start date's index.
     */
    public function up(): void
    {
        Schema::table('medical_facilities', function (Blueprint $table) {
            $table->date('e_prescription_started_on')->nullable()->index();
            $table->date('e_prescription_refill_started_on')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('medical_facilities', function (Blueprint $table) {
            $table->dropIndex(['e_prescription_started_on']);
            $table->dropColumn(['e_prescription_started_on', 'e_prescription_refill_started_on']);
        });
    }
};
