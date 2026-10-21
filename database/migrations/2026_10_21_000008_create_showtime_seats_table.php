<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('showtime_seats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('showtime_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seat_id')->index()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('price');
            $table->string('status')->default('available');
            $table->foreignId('held_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('held_until')->nullable();
            $table->foreignId('order_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['showtime_id', 'seat_id']);
            $table->index(['showtime_id', 'status']);
            $table->index(['status', 'held_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('showtime_seats');
    }
};
