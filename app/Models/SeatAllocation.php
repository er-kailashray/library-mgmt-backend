<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeatAllocation extends Model
{
    protected $fillable = [
        'library_id',
        'member_id',
        'seat_id',
        'batch_id',
        'start_date',
        'renewal_date',
        'ended_on',
        'status',
    ];

    protected $casts = [
        'ended_on' => 'date',
    ];

    public function library()
    {
        return $this->belongsTo(Library::class);
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    // Past seats keep pointing at their batch and seat even after those are deleted.
    public function batch()
    {
        return $this->belongsTo(Batch::class)->withTrashed();
    }

    public function seat()
    {
        return $this->belongsTo(Seat::class)->withTrashed();
    }
}
