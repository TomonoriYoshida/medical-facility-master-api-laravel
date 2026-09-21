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
        Schema::create('medical_facilities', function (Blueprint $table) {
            $table->id();
            $table->string('source_id')->unique();
            $table->unsignedTinyInteger('institution_type');
            $table->string('name');
            $table->string('name_kana')->nullable();
            $table->string('short_name')->nullable();
            $table->string('short_name_kana')->nullable();
            $table->string('name_en')->nullable();
            $table->string('prefecture_code', 2);
            $table->string('city_code', 3);
            $table->string('address');
            $table->decimal('latitude', 10, 6)->nullable();
            $table->decimal('longitude', 10, 6)->nullable();
            $table->string('website_url')->nullable();
            $table->json('closure_schedule');
            $table->json('business_hours')->nullable();
            $table->unsignedSmallInteger('general_beds')->nullable();
            $table->unsignedSmallInteger('sanatorium_beds')->nullable();
            $table->unsignedSmallInteger('sanatorium_beds_medical_insurance')->nullable();
            $table->unsignedSmallInteger('sanatorium_beds_care_insurance')->nullable();
            $table->unsignedSmallInteger('psychiatric_beds')->nullable();
            $table->unsignedSmallInteger('tuberculosis_beds')->nullable();
            $table->unsignedSmallInteger('infectious_disease_beds')->nullable();
            $table->unsignedSmallInteger('total_beds')->nullable();
            $table->timestamps();

            $table->index('institution_type');
            $table->index('prefecture_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('medical_facilities');
    }
};
