<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The 医療情報ネット's own ID for each facility (which its 診療時間票
     * rows refer to, see medical_info_net_schedules) and its regular days
     * off. Null on rows imported before these columns existed, until the
     * next `medical-info-net:import --force`.
     */
    public function up(): void
    {
        Schema::table('medical_info_net_locations', function (Blueprint $table) {
            $table->string('source_id', 20)->nullable()->after('id');
            // {weekly: list<day>, monthly: list<{week, day}>, holidays: ?bool, other: ?string}
            $table->json('closures')->nullable()->after('longitude');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('medical_info_net_locations', function (Blueprint $table) {
            $table->dropColumn(['source_id', 'closures']);
        });
    }
};
