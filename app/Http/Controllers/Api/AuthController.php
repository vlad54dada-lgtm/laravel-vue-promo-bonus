<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Видає Bearer-токен для демо-гравця. Реєстрація поза скоупом ТЗ:
     * гравці створюються сідером.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Невірний email або пароль.'],
            ]);
        }

        // Мультидевайс — норма: логін НЕ відкликає інші сесії гравця.
        // Від накопичення токенів захищає expiration (24 год) + logout;
        // прострочені чистить sanctum:prune-expired.
        return response()->json([
            'token' => $user->createToken('spa')->plainTextToken,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'balance_cents' => $user->balance_cents,
            ],
        ]);
    }

    /**
     * Відкликає поточний токен на сервері — «Вийти» не лише чистить
     * localStorage, а й робить викрадений токен марним.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Ви вийшли з акаунта.']);
    }

    /**
     * Поточний гравець з актуальним балансом (для шапки фронтенда).
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'balance_cents' => $user->balance_cents,
        ]);
    }
}
