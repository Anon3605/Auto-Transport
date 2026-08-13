<?php

namespace App\Enums;

enum QuoteStatus: string
{
    case Draft      = 'draft';
    case Sent       = 'sent';
    case Viewed     = 'viewed';
    case Accepted   = 'accepted';
    case Declined   = 'declined';
    case Expired    = 'expired';
    case Superseded = 'superseded';   // a newer version replaced this one
}
