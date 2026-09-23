<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * facility_code (the 7-digit 地方厚生局 code) is only guaranteed unique
     * within one (prefecture, institution_type) pair -- real Kanto-Shinetsu
     * data confirmed both: distinct, unrelated facilities in different
     * prefectures sharing the same code (e.g. "0110056" is both an
     * Ibaraki hospital and an unrelated Kanagawa clinic), and distinct
     * facilities of different institution types within the SAME
     * prefecture sharing a code (e.g. a Saitama medical clinic and an
     * unrelated Saitama dental clinic). Both silently overwrote each
     * other under the old (bureau_code, facility_code) constraint. A full
     * real-data scan (86,902 rows across all 10 Kanto-Shinetsu
     * prefectures and all 3 categories) found zero remaining collisions
     * once prefecture_code and institution_type are both included.
     */
    public function up(): void
    {
        Schema::table('medical_facilities', function (Blueprint $table) {
            $table->dropUnique(['bureau_code', 'facility_code']);
            $table->unique(
                ['bureau_code', 'prefecture_code', 'institution_type', 'facility_code'],
                'medical_facilities_bureau_prefecture_institution_facility_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('medical_facilities', function (Blueprint $table) {
            $table->dropUnique('medical_facilities_bureau_prefecture_institution_facility_unique');
            $table->unique(['bureau_code', 'facility_code']);
        });
    }
};
