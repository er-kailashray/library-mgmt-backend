<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Paid = 'paid';
    case Partial = 'partial';
    case Pending = 'pending';
    /** Money given back, stored as a negative amount so totals net it off on their own. */
    case Refunded = 'refunded';

    /**
     * Wire format expected by the mobile app (PAID / PARTIAL / PENDING / REFUNDED).
     */
    public function label(): string
    {
        return strtoupper($this->value);
    }
}
