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
            $table->string('facility_code', 7);
            $table->unsignedTinyInteger('bureau_code');
            $table->unsignedTinyInteger('institution_type')->index();
            $table->unsignedTinyInteger('status')->default(1)->index();
            $table->foreignId('last_seen_rhb_dataset_download_id')
                ->nullable()
                ->constrained('rhb_dataset_downloads')
                ->nullOnDelete();
            $table->string('name');
            $table->string('name_normalized')->nullable()->index();
            $table->string('prefecture_code', 2)->index();
            $table->string('postal_code', 8)->nullable();
            $table->string('address');
            $table->decimal('latitude', 10, 6)->nullable();
            $table->decimal('longitude', 10, 6)->nullable();
            $table->string('phone_number')->nullable();
            $table->string('founder_name')->nullable();
            $table->string('administrator_name')->nullable();
            $table->date('designated_on')->nullable();
            $table->json('designation_history')->nullable();
            $table->json('bed_counts')->nullable();
            $table->json('department_categories')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(['bureau_code', 'facility_code']);
            $table->index(
                ['institution_type', 'prefecture_code', 'status', 'last_seen_rhb_dataset_download_id'],
                'medical_facilities_reconcile_index',
            );
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
