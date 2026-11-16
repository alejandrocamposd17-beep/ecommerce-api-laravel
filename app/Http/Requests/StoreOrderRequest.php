<?php

namespace App\Http\Requests;

class StoreOrderRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'shipping_address' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
            'coupon_code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'La orden debe incluir al menos un producto.',
            'items.*.product_id.exists' => 'Uno de los productos seleccionados no existe.',
            'coupon_code.regex' => 'El código de cupón solo puede contener letras, números, guiones y guiones bajos.',
        ];
    }
}
