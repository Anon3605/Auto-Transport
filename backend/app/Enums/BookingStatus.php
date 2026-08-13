<?php

namespace App\Enums;

/**
 * Status columns are stored as VARCHAR and cast to these enums, NOT as MySQL
 * ENUM. Adding a state to a MySQL ENUM is an ALTER TABLE; adding a case here
 * is a code deploy. The DB stays dumb, the domain stays expressive.
 */
enum BookingStatus: string
{
    case PendingPayment = 'pending_payment';
    case Confirmed      = 'confirmed';
    case Assigned       = 'assigned';
    case PickedUp       = 'picked_up';
    case InTransit      = 'in_transit';
    case Delivered      = 'delivered';
    case Cancelled      = 'cancelled';

    /** Legal forward transitions. Enforce in a BookingStateMachine service. */
    public function allowedNext(): array
    {
        return match ($this) {
            self::PendingPayment => [self::Confirmed, self::Cancelled],
            self::Confirmed      => [self::Assigned, self::Cancelled],
            self::Assigned       => [self::PickedUp, self::Cancelled],
            self::PickedUp       => [self::InTransit, self::Delivered],
            self::InTransit      => [self::Delivered],
            self::Delivered, self::Cancelled => [],
        };
    }

    public function isTerminal(): bool
    {
        return $this->allowedNext() === [];
    }

    /** Only a delivered booking may be reviewed. */
    public function allowsReview(): bool
    {
        return $this === self::Delivered;
    }

    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => 'Awaiting Payment',
            self::Confirmed      => 'Confirmed',
            self::Assigned       => 'Carrier Assigned',
            self::PickedUp       => 'Picked Up',
            self::InTransit      => 'In Transit',
            self::Delivered      => 'Delivered',
            self::Cancelled      => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PendingPayment => 'warning',
            self::Confirmed, self::Assigned => 'info',
            self::PickedUp, self::InTransit => 'primary',
            self::Delivered => 'success',
            self::Cancelled => 'danger',
        };
    }
}
