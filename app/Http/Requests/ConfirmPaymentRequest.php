<?php

namespace App\Http\Requests;

class ConfirmPaymentRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_method' => ['nullable', 'string', 'max:100'],
        ];
    }
}
