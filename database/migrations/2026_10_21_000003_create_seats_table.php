<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seat_section_id')->index()->constrained()->cascadeOnDelete();
            $table->string('row_label', 10);
            $table->unsignedSmallInteger('seat_number');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['venue_id', 'row_label', 'seat_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seats');
    }
};
