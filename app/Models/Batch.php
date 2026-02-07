<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Batch extends Model
{
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
