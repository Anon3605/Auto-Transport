<?php

namespace App\Enums;

enum QuoteRequestStatus: string
{
    case New       = 'new';
    case Reviewing = 'reviewing';
    case Quoted    = 'quoted';
    case Accepted  = 'accepted';
    case Declined  = 'declined';
    case Expired   = 'expired';
    case Spam      = 'spam';

    public function isOpen(): bool
    {
        return in_array($this, [self::New, self::Reviewing, self::Quoted], true);
    }
}
