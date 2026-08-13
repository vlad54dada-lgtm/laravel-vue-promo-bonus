<?php

namespace App\Exceptions;

class PromoAlreadyUsedException extends PromoException
{
    protected $message = 'Ви вже використали цей промокод раніше.';

    public function errorCode(): string
    {
        return 'PROMO_ALREADY_USED';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
