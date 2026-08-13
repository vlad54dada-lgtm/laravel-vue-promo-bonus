<?php

namespace App\Exceptions;

class ClaimAlreadyRevokedException extends PromoException
{
    protected $message = 'Це нарахування вже скасовано — кошти не списуються вдруге.';

    public function errorCode(): string
    {
        return 'CLAIM_ALREADY_REVOKED';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
