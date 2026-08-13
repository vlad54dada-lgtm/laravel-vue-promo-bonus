<?php

namespace App\Exceptions;

class PromoExpiredException extends PromoException
{
    protected $message = 'Термін дії цього промокоду завершився.';

    public function errorCode(): string
    {
        return 'PROMO_EXPIRED';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
