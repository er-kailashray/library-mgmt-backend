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
        'status',
    ];

    public function library()
    {
        return $this->belongsTo(Library::class);
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function batch()
    {
        return $this->belongsTo(Batch::class);
    }

    public function seat()
    {
        return $this->belongsTo(Seat::class);
    }
}
