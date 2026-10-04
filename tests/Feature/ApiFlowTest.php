<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_product_listing(): void
    {
        Product::factory()->count(3)->create();

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(3, 'data');
    }

    public function test_register_login_and_create_order(): void
    {
        $product = Product::factory()->create(['price' => 10, 'stock' => 5]);

        $register = $this->postJson('/api/auth/register', [
            'name' => 'Alejandro Campos',
            'email' => 'ale@test.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();

        $token = $register->json('data.token');

        $this->withToken($token)->postJson('/api/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ])->assertCreated()
            ->assertJsonPath('data.subtotal', 20)
            ->assertJsonPath('data.total', 22.6);

        $this->assertEquals(3, $product->fresh()->stock);

        $this->withToken($token)->getJson('/api/orders')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_customer_cannot_create_products(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);

        $this->actingAs($customer)->postJson('/api/products', ['name' => 'X', 'price' => 1, 'stock' => 1])
            ->assertForbidden();
    }

    public function test_admin_can_create_products(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson('/api/products', ['name' => 'Nuevo', 'price' => 9.99, 'stock' => 3])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Nuevo');
    }

    public function test_insufficient_stock_returns_409(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['stock' => 1]);

        $this->actingAs($user)->postJson('/api/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
        ])->assertStatus(409);
    }
}
