<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The stations of the MLIT 国土数値情報「鉄道」(N02) data, one row per
     * station of a line: a station served by several lines has one row for
     * each, sharing the group_code. The position is the middle of the
     * station's platform line, and the prefecture and municipality are
     * those of the facility nearest to the group, as the data has neither. Looked up by
     * name for the station search (/api/v1/stations) and replaced as a whole
     * on each import (stations:import).
     */
    public function up(): void
    {
        Schema::create('railway_stations', function (Blueprint $table) {
            $table->id();
            $table->char('station_code', 6)->unique();
            $table->char('group_code', 6);
            $table->string('name');
            // Binary, so that names differing only in kana voicing (ハ / バ) or
            // hiragana / katakana are not taken for one another.
            $table->string('name_normalized')->collation('utf8mb4_bin')->index();
            $table->string('line_name');
            $table->string('operator_name');
            $table->char('prefecture_code', 2)->nullable();
            $table->char('municipality_code', 5)->nullable();
            $table->decimal('latitude', 10, 6);
            $table->decimal('longitude', 10, 6);
            $table->datetimes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('railway_stations');
    }
};
