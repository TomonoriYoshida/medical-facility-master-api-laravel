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
        Schema::create('medical_facility_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medical_facility_id')->constrained()->restrictOnDelete();
            $table->string('department_code')->nullable();
            $table->unsignedTinyInteger('event_type');
            $table->date('occurred_on');
            $table->json('payload')->nullable();
            $table->foreignId('mhlw_dataset_download_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['event_type', 'department_code', 'occurred_on'], 'medical_facility_events_type_dept_date_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('medical_facility_events');
    }
};
