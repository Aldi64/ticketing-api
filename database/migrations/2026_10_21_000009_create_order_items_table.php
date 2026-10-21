<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('showtime_seat_id')->index()->constrained()->restrictOnDelete();
            $table->string('seat_label');
            $table->unsignedBigInteger('price');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
        });

        // A seat can only belong to ONE active order item at a time. Released items
        // (expired or failed orders) are excluded, so the seat can be sold again.
        DB::statement(
            'CREATE UNIQUE INDEX order_items_active_seat_unique
             ON order_items (showtime_seat_id) WHERE released_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
