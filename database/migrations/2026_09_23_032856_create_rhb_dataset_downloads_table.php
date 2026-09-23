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
        Schema::create('rhb_dataset_downloads', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('bureau_code');
            $table->unsignedTinyInteger('category');
            $table->json('prefecture_codes');
            $table->string('filename');
            $table->string('source_url');
            $table->string('local_path');
            $table->date('published_on');
            $table->dateTime('downloaded_at');
            $table->timestamps();

            $table->unique(['bureau_code', 'category', 'filename']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rhb_dataset_downloads');
    }
};
