<?php

namespace App\Services\Subscriptions;

enum SubscriptionState: string
{
    /** Free trial, full access. */
    case Trial = 'trial';
    /** Paid plan, full access. */
    case Active = 'active';
    /** Trial or plan ended a few days ago; still full access while the owner renews. */
    case Grace = 'grace';
    /** Past the grace period: read-only until the owner subscribes. */
    case Expired = 'expired';

    public function canWrite(): bool
    {
        return $this !== self::Expired;
    }
}
