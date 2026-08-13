<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Базовий доменний виняток promo-модуля.
 *
 * Laravel сам викликає render(): кожна доменна відмова перетворюється
 * на JSON {message, error_code} з коректним HTTP-статусом — контракт
 * помилок з docs/REQUIREMENTS.md.
 */
abstract class PromoException extends Exception
{
    abstract public function errorCode(): string;

    abstract public function httpStatus(): int;

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error_code' => $this->errorCode(),
        ], $this->httpStatus());
    }
}
