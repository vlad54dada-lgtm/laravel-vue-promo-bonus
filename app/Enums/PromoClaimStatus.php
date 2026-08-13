<?php

namespace App\Enums;

enum PromoClaimStatus: string
{
    case Applied = 'applied';
    case Revoked = 'revoked';
    case Rejected = 'rejected';
}
