<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each facility's match in the 医療情報ネット (its ID there), found by
     * facilities:assign-opening-hours, so that the opening hours endpoint
     * and the open_at filter agree. Setting it never moves updated_at: it
     * is not part of the facility's served data.
     */
    public function up(): void
    {
        Schema::table('medical_facilities', function (Blueprint $table) {
            $table->string('medical_info_net_id', 20)->nullable();
        });

        Schema::table('medical_info_net_locations', function (Blueprint $table) {
            $table->index('source_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('medical_info_net_locations', function (Blueprint $table) {
            $table->dropIndex(['source_id']);
        });

        Schema::table('medical_facilities', function (Blueprint $table) {
            $table->dropColumn('medical_info_net_id');
        });
    }
};
