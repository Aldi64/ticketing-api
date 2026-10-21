<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    /** status and paid_at are NOT mass assignable: only the payment flow changes them. */
    protected $fillable = [
        'user_id',
        'showtime_id',
        'total_amount',
        'midtrans_order_id',
        'snap_token',
        'expires_at',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'integer',
            'status' => OrderStatus::class,
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            $order->code ??= Str::upper(Str::random(10));
        });
    }

    public function isPending(): bool
    {
        return $this->status === OrderStatus::Pending;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function showtime(): BelongsTo
    {
        return $this->belongsTo(Showtime::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function seats(): HasMany
    {
        return $this->hasMany(ShowtimeSeat::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
