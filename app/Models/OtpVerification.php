<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OtpVerification extends Model
{
    public const MAX_ATTEMPTS = 5;

    protected $fillable = [

        'user_id',
        'otp',
        'type',
        'is_verified',
        'expires_at',
        'attempts',
        'channel',
    ];

    protected $casts = [

        'is_verified' => 'boolean',
        'expires_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
