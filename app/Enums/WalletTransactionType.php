<?php

namespace App\Enums;

enum WalletTransactionType: string
{
    case PromoClaim = 'promo_claim';
    case PromoRevoke = 'promo_revoke';
}
