<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Batch extends Model
{
    // Deleting keeps the row for our records; the app never shows or restores it.
    use SoftDeletes;

    protected $fillable = [
        'library_id',
        'name',
        'start_time',
        'end_time',
        'monthly_fee',
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
