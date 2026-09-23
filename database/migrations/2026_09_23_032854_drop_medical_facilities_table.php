<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('medical_facilities');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Irreversible: recreated fresh with a completely different column
        // set (地方厚生局 source -- see create_medical_facilities_table).
    }
};
