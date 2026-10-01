<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Coordinates from the MHLW 医療情報ネット open data, one row per facility
     * it lists. Its facilities have no code shared with the bureaus' data, so
     * rows are matched by type, municipality and normalized name / address
     * (the *_key columns); facilities:geocode uses them where the Address
     * Base Registry only reaches the 町丁目 or nothing. Replaced as a whole on
     * each import (medical-info-net:import).
     */
    public function up(): void
    {
        Schema::create('medical_info_net_locations', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('institution_type');
            $table->char('municipality_code', 5);
            $table->string('name_key');
            $table->string('address_key');
            // Null for facilities listed with "0.0": kept so that matching by
            // name still sees them.
            $table->decimal('latitude', 10, 6)->nullable();
            $table->decimal('longitude', 10, 6)->nullable();
            $table->date('published_on');
            $table->datetimes();

            // The default name is over MySQL's 64-character limit.
            $table->index(['municipality_code', 'institution_type'], 'medical_info_net_locations_municipality_type_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('medical_info_net_locations');
    }
};
