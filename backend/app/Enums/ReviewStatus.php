<?php

namespace App\Enums;

enum ReviewStatus: string
{
    case Pending  = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /** Only approved reviews feed public listings and rating aggregates. */
    public function isPublic(): bool
    {
        return $this === self::Approved;
    }
}
