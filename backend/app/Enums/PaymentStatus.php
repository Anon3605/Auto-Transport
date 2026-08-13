<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Pending    = 'pending';
    case Authorized = 'authorized';
    case Captured   = 'captured';
    case Failed     = 'failed';
    case Refunded   = 'refunded';
    case Disputed   = 'disputed';

    /** Only these count toward bookings.amount_paid_cents. */
    public function countsAsPaid(): bool
    {
        return $this === self::Captured;
    }
}
