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
        Schema::create('mhlw_dataset_downloads', function (Blueprint $table) {
            $table->id();
            $table->string('dataset_key');
            $table->string('filename');
            $table->date('published_on');
            $table->string('source_url');
            $table->string('local_path');
            $table->timestamp('downloaded_at');
            $table->timestamps();

            $table->unique(['dataset_key', 'filename']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mhlw_dataset_downloads');
    }
};
