<?php

namespace App\Models;

use App\Enums\EventCategory;
use App\Enums\EventStatus;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory;

    /**
     * status, reviewed_by, reviewed_at and rejection_reason are NOT mass assignable:
     * only the admin review flow should change them.
     */
    protected $fillable = [
        'organizer_id',
        'title',
        'slug',
        'category',
        'description',
        'poster_path',
    ];

    protected $attributes = [
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'category' => EventCategory::class,
            'status' => EventStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', EventStatus::Published->value);
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function showtimes(): HasMany
    {
        return $this->hasMany(Showtime::class);
    }
}
