<?php

namespace Database\Seeders;

use App\Models\Coupon;
use Illuminate\Database\Seeder;

class CouponSeeder extends Seeder
{
    public function run(): void
    {
        Coupon::updateOrCreate(['code' => 'BIENVENIDA10'], [
            'type' => Coupon::TYPE_PERCENT,
            'value' => 10,
            'max_uses' => 1000,
            'is_active' => true,
        ]);

        Coupon::updateOrCreate(['code' => 'ENVIOGRATIS'], [
            'type' => Coupon::TYPE_FIXED,
            'value' => 5,
            'min_subtotal' => 50,
            'is_active' => true,
        ]);
    }
}
