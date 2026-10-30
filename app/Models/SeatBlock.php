<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** A seat out of use for a while, in one batch or (batch_id null) in all of them. */
class SeatBlock extends Model
{
    protected $fillable = [
        'library_id',
        'seat_id',
        'batch_id',
        'starts_on',
        'ends_on',
        'reason',
    ];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
    ];

    public function seat()
    {
        return $this->belongsTo(Seat::class)->withTrashed();
    }

    public function batch()
    {
        return $this->belongsTo(Batch::class)->withTrashed();
    }

    /** In force on this day. */
    public function scopeActiveOn(Builder $query, CarbonInterface $day): Builder
    {
        $date = $day->toDateString();

        return $query->whereDate('starts_on', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date));
    }

    /** In force on this day or starting later - what the owner may still want to see or remove. */
    public function scopeCurrentOrUpcoming(Builder $query, CarbonInterface $day): Builder
    {
        $date = $day->toDateString();

        return $query->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date));
    }

    /** Covers this batch (blocks with no batch cover every batch). */
    public function covers(int $batchId): bool
    {
        return $this->batch_id === null || $this->batch_id === $batchId;
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'batch_id' => $this->batch_id,
            'all_batches' => $this->batch_id === null,
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'reason' => $this->reason,
        ];
    }
}
