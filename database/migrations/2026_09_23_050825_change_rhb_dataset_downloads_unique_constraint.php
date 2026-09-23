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
        Schema::table('rhb_dataset_downloads', function (Blueprint $table) {
            $table->dropUnique(['bureau_code', 'category', 'filename']);
            $table->unique(['bureau_code', 'category', 'filename', 'published_on'], 'rhb_dataset_downloads_bureau_category_filename_published_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rhb_dataset_downloads', function (Blueprint $table) {
            $table->dropUnique('rhb_dataset_downloads_bureau_category_filename_published_unique');
            $table->unique(['bureau_code', 'category', 'filename']);
        });
    }
};
