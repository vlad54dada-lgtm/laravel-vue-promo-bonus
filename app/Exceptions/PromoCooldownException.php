<?php

namespace App\Exceptions;

use Illuminate\Support\Carbon;

class PromoCooldownException extends PromoException
{
    public function __construct(public readonly Carbon $nextClaimAvailableAt)
    {
        $hours = (int) config('promo.cooldown_hours');

        parent::__construct("Промокод можна застосовувати раз на {$hours} год.");
    }

    public function errorCode(): string
    {
        return 'PROMO_COOLDOWN';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    protected function extra(): array
    {
        return [
            'next_claim_available_at' => $this->nextClaimAvailableAt->toISOString(),
        ];
    }
}
