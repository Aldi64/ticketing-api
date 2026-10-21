<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->index()->constrained()->cascadeOnDelete();
            $table->string('transaction_id');
            $table->string('transaction_status');
            $table->string('payment_type')->nullable();
            $table->string('fraud_status')->nullable();
            $table->unsignedBigInteger('gross_amount');
            $table->boolean('signature_valid');
            $table->jsonb('raw_payload');
            $table->timestamps();

            // Makes the Midtrans webhook idempotent: the same notification can't be stored twice.
            $table->unique(['transaction_id', 'transaction_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
