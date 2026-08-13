<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClaimPromoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // гравець — будь-який автентифікований користувач
    }

    /**
     * Формат з ТЗ: обов'язково, 6–12 символів, латинські літери й цифри.
     * Некоректний формат → 422 (стандартна валідація Laravel).
     */
    public function rules(): array
    {
        // \A..\z замість ^..$: у PCRE $ матчить і перед фінальним \n,
        // тож "CODE\n" пройшов би валідацію і потрапив у доменний шар.
        return [
            'code' => ['required', 'string', 'regex:/\A[A-Za-z0-9]{6,12}\z/'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'Введіть промокод.',
            'code.regex' => 'Промокод має містити 6–12 символів: лише латинські літери та цифри.',
        ];
    }
}
