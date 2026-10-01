<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('medical_facilities', function (Blueprint $table) {
            // 住所から判定した市区町村コード。判定できない住所はnull
            $table->char('municipality_code', 5)->nullable()->after('prefecture_code');
            $table->index('municipality_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('medical_facilities', function (Blueprint $table) {
            $table->dropIndex(['municipality_code']);
            $table->dropColumn('municipality_code');
        });
    }
};
