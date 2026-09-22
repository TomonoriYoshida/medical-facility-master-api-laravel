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
        Schema::table('medical_facility_departments', function (Blueprint $table) {
            $table->unique(
                ['medical_facility_id', 'department_code'],
                'medical_facility_departments_facility_dept_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('medical_facility_departments', function (Blueprint $table) {
            $table->dropUnique('medical_facility_departments_facility_dept_unique');
        });
    }
};
