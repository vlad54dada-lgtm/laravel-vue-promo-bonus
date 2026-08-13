<?php

namespace App\Exceptions;

class ClaimNotRevocableException extends PromoException
{
    protected $message = 'Це нарахування неможливо скасувати: бонус за ним не нараховувався.';

    public function errorCode(): string
    {
        return 'CLAIM_NOT_REVOCABLE';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
