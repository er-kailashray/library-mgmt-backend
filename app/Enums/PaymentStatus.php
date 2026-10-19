<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Paid = 'paid';
    case Partial = 'partial';
    case Pending = 'pending';

    /**
     * Wire format expected by the mobile app (PAID / PARTIAL / PENDING).
     */
    public function label(): string
    {
        return strtoupper($this->value);
    }
}
