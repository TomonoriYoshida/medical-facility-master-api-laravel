<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * latitude/longitude already exist (kept for geocoding since the bureaus'
     * data has no coordinates). geocode_level records how precisely they
     * were located, and geocoded_address the address they were located
     * from, so that only new or changed addresses are geocoded again.
     */
    public function up(): void
    {
        Schema::table('medical_facilities', function (Blueprint $table) {
            $table->unsignedTinyInteger('geocode_level')->nullable()->after('longitude');
            $table->string('geocoded_address')->nullable()->after('geocode_level');
            $table->index(['latitude', 'longitude']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('medical_facilities', function (Blueprint $table) {
            $table->dropIndex(['latitude', 'longitude']);
            $table->dropColumn(['geocode_level', 'geocoded_address']);
        });
    }
};
