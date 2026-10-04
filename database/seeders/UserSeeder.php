<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(['email' => 'admin@ecommerce.com'], [
            'name' => 'Administrador',
            'password' => 'password',
            'role' => 'admin',
            'phone' => '+503 2200-0000',
            'address' => 'San Salvador, El Salvador',
        ]);

        User::updateOrCreate(['email' => 'alejandro@example.com'], [
            'name' => 'Alejandro Campos',
            'password' => 'password',
            'role' => 'customer',
            'phone' => '+503 7000-0000',
            'address' => 'Soyapango, San Salvador',
        ]);

        User::updateOrCreate(['email' => 'cliente@example.com'], [
            'name' => 'Cliente de Prueba',
            'password' => 'password',
            'role' => 'customer',
        ]);
    }
}
