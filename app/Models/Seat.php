<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Seat extends Model
{
    // Deleting keeps the row for our records; the app never shows or restores it.
    use SoftDeletes;

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
