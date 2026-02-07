<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'library_id',
        'member_id',
        'amount',
        'payment_date',
        'payment_mode',
        'notes',
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
