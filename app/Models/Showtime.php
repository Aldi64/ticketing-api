<?php

namespace App\Models;

use App\Enums\ShowtimeStatus;
use Database\Factories\ShowtimeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Showtime extends Model
{
    /** @use HasFactory<ShowtimeFactory> */
    use HasFactory;

    protected $fillable = [
        'event_id',
        'venue_id',
        'starts_at',
        'status',
    ];

    protected $attributes = [
        'status' => 'scheduled',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'status' => ShowtimeStatus::class,
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function sectionPrices(): HasMany
    {
        return $this->hasMany(ShowtimeSectionPrice::class);
    }

    public function seats(): HasMany
    {
        return $this->hasMany(ShowtimeSeat::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
