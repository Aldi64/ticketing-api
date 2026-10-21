<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('showtime_section_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('showtime_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seat_section_id')->index()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('price');
            $table->timestamps();

            $table->unique(['showtime_id', 'seat_section_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('showtime_section_prices');
    }
};
