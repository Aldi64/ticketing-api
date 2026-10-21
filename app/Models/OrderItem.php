<?php

namespace App\Models;

use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    protected $fillable = [
        'order_id',
        'showtime_seat_id',
        'seat_label',
        'price',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'released_at' => 'datetime',
        ];
    }

    /** Items that still own their seat (not released by an expired or failed order). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('released_at');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function showtimeSeat(): BelongsTo
    {
        return $this->belongsTo(ShowtimeSeat::class);
    }

    public function ticket(): HasOne
    {
        return $this->hasOne(Ticket::class);
    }
}
