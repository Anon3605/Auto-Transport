<?php

namespace App\Enums;

/**
 * Role NAMES only. Actual permission grants live in the permissions table so
 * they can be edited from the admin panel without a deploy.
 */
enum UserRole: string
{
    case SuperAdmin = 'super-admin';   // full access incl. settings + role editing
    case Admin      = 'admin';         // operations manager
    case Dispatcher = 'dispatcher';    // quotes, bookings, carrier assignment
    case Support    = 'support';       // contact messages, review moderation, read-only bookings
    case Driver     = 'driver';        // own assigned bookings, status updates, BOL photos
    case Customer   = 'customer';      // own quotes, bookings, reviews

    public function isStaff(): bool
    {
        return in_array($this, [
            self::SuperAdmin, self::Admin, self::Dispatcher, self::Support,
        ], true);
    }
}
