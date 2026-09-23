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
            // Precomputed via AddressNormalizer, same rationale as
            // name_normalized: search-time cost is normalizing the query
            // string once, not every stored row.
            $table->string('address_normalized')->nullable()->after('address');
            $table->index('address_normalized');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('medical_facilities', function (Blueprint $table) {
            $table->dropIndex(['address_normalized']);
            $table->dropColumn('address_normalized');
        });
    }
};
