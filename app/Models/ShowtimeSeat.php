<?php

namespace App\Models;

use App\Enums\SeatStatus;
use Database\Factories\ShowtimeSeatFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShowtimeSeat extends Model
{
    /** @use HasFactory<ShowtimeSeatFactory> */
    use HasFactory;

    protected $fillable = [
        'showtime_id',
        'seat_id',
        'price',
        'status',
        'held_by',
        'held_until',
        'order_id',
    ];

    protected $attributes = [
        'status' => 'available',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'status' => SeatStatus::class,
            'held_until' => 'datetime',
        ];
    }

    /**
     * Seats that can be taken right now: available, or held with an expired hold.
     * Correctness never depends on the cleanup job having run.
     */
    public function scopeTakeable(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('status', SeatStatus::Available->value)
                ->orWhere(function (Builder $q) {
                    $q->where('status', SeatStatus::Held->value)
                        ->where('held_until', '<', now());
                });
        });
    }

    public function isTakeable(): bool
    {
        return $this->status === SeatStatus::Available
            || ($this->status === SeatStatus::Held && $this->held_until?->isPast());
    }

    public function showtime(): BelongsTo
    {
        return $this->belongsTo(Showtime::class);
    }

    public function seat(): BelongsTo
    {
        return $this->belongsTo(Seat::class);
    }

    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'held_by');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
