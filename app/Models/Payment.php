<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'order_id',
        'transaction_id',
        'transaction_status',
        'payment_type',
        'fraud_status',
        'gross_amount',
        'signature_valid',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'gross_amount' => 'integer',
            'signature_valid' => 'boolean',
            'raw_payload' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
