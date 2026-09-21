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
        Schema::create('medical_facility_departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medical_facility_id')->constrained()->cascadeOnDelete();
            $table->string('department_code');
            $table->string('department_name');
            $table->json('consultation_hours');
            $table->json('reception_hours');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('medical_facility_departments');
    }
};
