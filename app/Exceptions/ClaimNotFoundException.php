<?php

namespace App\Exceptions;

class ClaimNotFoundException extends PromoException
{
    protected $message = 'Нарахування не знайдено.';

    public function errorCode(): string
    {
        return 'CLAIM_NOT_FOUND';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
