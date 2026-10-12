<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Set when a bureau replaces a file without changing the page's "current
     * as of" date (関東信越厚生局 swapped "shitei_ika_r0810.zip" for
     * "shitei_ika_r0810-1.zip" on 2026-10-09). The replaced file shares
     * published_on with its replacement, so without this both would count as
     * current and be imported together.
     */
    public function up(): void
    {
        Schema::table('rhb_dataset_downloads', function (Blueprint $table) {
            $table->dateTime('superseded_at')->nullable()->after('imported_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rhb_dataset_downloads', function (Blueprint $table) {
            $table->dropColumn('superseded_at');
        });
    }
};
