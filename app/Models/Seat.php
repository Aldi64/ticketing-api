<?php

namespace App\Models;

use Database\Factories\SeatFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Seat extends Model
{
    /** @use HasFactory<SeatFactory> */
    use HasFactory;

    protected $fillable = [
        'venue_id',
        'seat_section_id',
        'row_label',
        'seat_number',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'seat_number' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(SeatSection::class, 'seat_section_id');
    }

    public function showtimeSeats(): HasMany
    {
        return $this->hasMany(ShowtimeSeat::class);
    }

    /** Human label such as "VIP-A12". Requires the section relation to be loaded or loadable. */
    public function label(): string
    {
        return $this->section->name.'-'.$this->row_label.$this->seat_number;
    }
}
