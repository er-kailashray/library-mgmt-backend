<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'library_id',
        'member_id',
        'amount',
        'status',
        'payment_date',
        'period_start',
        'period_end',
        'seats',
        'payment_mode',
        'notes',
    ];

    protected $casts = [
        'amount' => 'float',
        'status' => PaymentStatus::class,
        'payment_date' => 'date',
        'period_start' => 'date',
        'period_end' => 'date',
        'seats' => 'array',
    ];

    public function library()
    {
        return $this->belongsTo(Library::class);
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }
}
