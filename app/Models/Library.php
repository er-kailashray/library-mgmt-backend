<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Library extends Model
{
    protected $fillable = [
        'user_id',
        'library_name',
        'total_seats',
        'amenities',
        'address',
        'latitude',
        'longitude',
    ];

    protected $casts = [
        'amenities' => 'array',
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function seats()
    {
        return $this->hasMany(Seat::class);
    }

    public function subscription()
    {
        return $this->hasOne(Subscription::class);
    }

    public function photos()
    {
        return $this->hasMany(LibraryPhoto::class);
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
