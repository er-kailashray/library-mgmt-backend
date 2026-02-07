<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Member extends Model
{
    protected $fillable = [
        'library_id',
        'name',
        'phone',
        'join_date',
        'status',
    ];

    public function library()
    {
        return $this->belongsTo(Library::class);
    }

    public function allocations()
    {
        return $this->hasMany(SeatAllocation::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }
}
