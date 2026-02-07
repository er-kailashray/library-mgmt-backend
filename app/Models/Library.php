<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Library extends Model
{
    protected $fillable = [
        'user_id',
        'library_name',
        'total_seats',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function seats()
    {
        return $this->hasMany(Seat::class);
    }

    public function batches()
    {
        return $this->hasMany(Batch::class);
    }

    public function members()
    {
        return $this->hasMany(Member::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }
}
