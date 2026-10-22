<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The website and the reading (furigana) of a facility's name, as the
     * 医療情報ネット lists them: kept per 医療情報ネット facility by
     * medical-info-net:import, and copied to the matched facility by
     * facilities:assign-opening-hours. URLs in the source reach 300
     * characters; a longer one is not kept.
     */
    public function up(): void
    {
        Schema::table('medical_info_net_locations', function (Blueprint $table) {
            $table->string('name_kana')->nullable();
            $table->string('website_url', 500)->nullable();
        });

        Schema::table('medical_facilities', function (Blueprint $table) {
            $table->string('name_kana')->nullable();
            $table->string('website_url', 500)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('medical_facilities', function (Blueprint $table) {
            $table->dropColumn(['name_kana', 'website_url']);
        });

        Schema::table('medical_info_net_locations', function (Blueprint $table) {
            $table->dropColumn(['name_kana', 'website_url']);
        });
    }
};
