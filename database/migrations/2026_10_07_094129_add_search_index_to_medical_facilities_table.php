<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Speeds up counting the list API's q search results. Its LIKE '%word%'
     * cannot seek any index, so MySQL scans every row either way, but this
     * index holds only the two searched columns: scanning it instead of the
     * table cut the count from ~0.095s to ~0.065s locally, for any word.
     * (An ngram FULLTEXT index was measured too: 10-20x faster for rare
     * words, but 3-10x slower for common ones such as 歯科 or クリニック.)
     */
    public function up(): void
    {
        Schema::table('medical_facilities', function (Blueprint $table) {
            $table->index(['name_normalized', 'address_normalized'], 'medical_facilities_search_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('medical_facilities', function (Blueprint $table) {
            $table->dropIndex('medical_facilities_search_index');
        });
    }
};
