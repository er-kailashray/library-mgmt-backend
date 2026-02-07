<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Seat extends Model
{
    protected $fillable = [
        'library_id',
        'seat_number',
    ];

    public function library()
    {
        return $this->belongsTo(Library::class);
    }

    public function allocations()
    {
        return $this->hasMany(SeatAllocation::class);
    }
}
