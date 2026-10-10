<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Coordinates from the MLIT 国土数値情報「医療機関」(P04) data, one row per
     * hospital, clinic and dental clinic it lists. Like
     * medical_info_net_locations (whose columns these mirror, so that
     * MedicalInfoNetMatcher matches both), rows are matched by type,
     * municipality and normalized name / address; facilities:geocode uses
     * them where neither the Address Base Registry (beyond the 町丁目) nor
     * the 医療情報ネット locates a facility. Replaced as a whole on each
     * import (national-land:import).
     */
    public function up(): void
    {
        Schema::create('national_land_medical_locations', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('institution_type');
            $table->char('municipality_code', 5);
            $table->string('name_key');
            $table->string('address_key');
            $table->decimal('latitude', 10, 6);
            $table->decimal('longitude', 10, 6);
            $table->datetimes();

            // The default name is over MySQL's 64-character limit.
            $table->index(['municipality_code', 'institution_type'], 'national_land_medical_locations_municipality_type_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('national_land_medical_locations');
    }
};
