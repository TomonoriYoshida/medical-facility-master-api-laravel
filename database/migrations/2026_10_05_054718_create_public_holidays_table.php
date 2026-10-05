<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Japan's public holidays (国民の祝日・休日, including 振替休日 and 国民の休日)
     * from the Cabinet Office's list, which covers 1955 to the end of the
     * next year. Replaced as a whole on each import (holidays:import). Used
     * for the opening hours of holidays (open_at) and served as is.
     */
    public function up(): void
    {
        Schema::create('public_holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('name');
            $table->datetimes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('public_holidays');
    }
};
