<?php

namespace App\Http\Requests;

use App\Models\Coupon;
use Illuminate\Validation\Rule;

/** Validación para crear (POST) y actualizar (PUT/PATCH) cupones. */
class CouponRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }

    public function rules(): array
    {
        $updating = $this->isMethod('put') || $this->isMethod('patch');
        $required = $updating ? 'sometimes' : 'required';
        $coupon = $this->route('coupon');

        return [
            'code' => [$required, 'string', 'min:3', 'max:40', 'regex:/^[A-Z0-9_-]+$/',
                Rule::unique('coupons', 'code')->ignore($coupon instanceof Coupon ? $coupon->id : null)],
            'type' => [$required, Rule::in([Coupon::TYPE_PERCENT, Coupon::TYPE_FIXED])],
            'value' => [$required, 'numeric', 'min:0.01', 'max:999999.99',
                // Un porcentaje no puede pasar de 100
                Rule::when(fn () => $this->input('type', $coupon instanceof Coupon ? $coupon->type : null) === Coupon::TYPE_PERCENT, ['max:100'])],
            'min_subtotal' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
