<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Population per municipality from 総務省「住民基本台帳に基づく人口、人口動態及び
     * 世帯数」 (as of January 1, published yearly), for counts per capita in the
     * stats API. Kept apart from municipalities, which is re-seeded from the
     * Address Base Registry. Replaced as a whole on each import (population:import).
     */
    public function up(): void
    {
        Schema::create('municipality_populations', function (Blueprint $table) {
            $table->char('municipality_code', 5)->primary();
            $table->unsignedInteger('population');
            $table->date('as_of');
            $table->datetimes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('municipality_populations');
    }
};
