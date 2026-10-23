<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionPayment extends Model
{
    protected $fillable = [
        'subscription_id',
        'plan_id',
        'amount',
        'currency',
        'provider',
        'provider_order_id',
        'provider_payment_id',
        'status',
        'paid_at',
        'invoice_number',
        'payload',
    ];

    protected $casts = [
        'amount' => 'float',
        'paid_at' => 'datetime',
        'payload' => 'array',
    ];

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    public function plan()
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }
}
