<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShowtimeSectionPrice extends Model
{
    protected $fillable = [
        'showtime_id',
        'seat_section_id',
        'price',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
        ];
    }

    public function showtime(): BelongsTo
    {
        return $this->belongsTo(Showtime::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(SeatSection::class, 'seat_section_id');
    }
}
