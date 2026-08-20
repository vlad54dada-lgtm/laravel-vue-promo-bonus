<?php

namespace App\Enums;

enum PromoRejectReason: string
{
    case NotFound = 'not_found';
    case Expired = 'expired';
    case AlreadyUsed = 'already_used';
    case Cooldown = 'cooldown';
}
