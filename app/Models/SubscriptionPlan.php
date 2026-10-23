<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionPlan extends Model
{
    protected $fillable = [
        'code',
        'name',
        'price',
        'duration_months',
        'is_active',
    ];

    protected $casts = [
        'price' => 'float',
        'duration_months' => 'integer',
        'is_active' => 'boolean',
    ];
}
