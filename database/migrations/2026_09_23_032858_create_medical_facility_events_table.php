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
            $table->unsignedTinyInteger('event_type');
            $table->date('occurred_on');
            $table->json('payload')->nullable();
            $table->foreignId('rhb_dataset_download_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index(['event_type', 'occurred_on'], 'medical_facility_events_type_date_index');
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
