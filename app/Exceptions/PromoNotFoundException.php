<?php

namespace App\Exceptions;

class PromoNotFoundException extends PromoException
{
    protected $message = 'Промокод не знайдено. Перевірте, чи правильно введено код.';

    public function errorCode(): string
    {
        return 'PROMO_NOT_FOUND';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
