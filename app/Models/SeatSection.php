<?php

namespace App\Models;

use Database\Factories\SeatSectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeatSection extends Model
{
    /** @use HasFactory<SeatSectionFactory> */
    use HasFactory;

    protected $fillable = [
        'venue_id',
        'name',
    ];

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function seats(): HasMany
    {
        return $this->hasMany(Seat::class);
    }
}
