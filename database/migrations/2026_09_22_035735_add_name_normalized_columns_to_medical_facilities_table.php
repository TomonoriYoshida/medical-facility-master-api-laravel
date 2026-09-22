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
        Schema::table('medical_facilities', function (Blueprint $table) {
            // Precomputed via ItaijiNormalizer (NFKC + kanji itaiji unification)
            // so search-time cost is normalizing the query string once, not
            // every stored row. Single-column indexes only serve prefix
            // matches (LIKE 'foo%'); substring search (LIKE '%foo%') would
            // need a different indexing strategy, out of scope for now.
            $table->string('name_normalized')->nullable()->after('name');
            $table->string('short_name_normalized')->nullable()->after('short_name');
            $table->index('name_normalized');
            $table->index('short_name_normalized');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('medical_facilities', function (Blueprint $table) {
            $table->dropIndex(['name_normalized']);
            $table->dropIndex(['short_name_normalized']);
            $table->dropColumn(['name_normalized', 'short_name_normalized']);
        });
    }
};
