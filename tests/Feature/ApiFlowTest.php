<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Services\StripeService;
use Mockery\MockInterface;
use Stripe\Exception\CardException;
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

    /** Crea una orden de 2 unidades (stock 5 -> 3) con su intento de pago. */
    private function pendingOrderWithPayment(User $user, Product $product): array
    {
        $orderId = $this->actingAs($user)->postJson('/api/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ])->assertCreated()->json('data.id');

        $order = Order::findOrFail($orderId);
        $payment = Payment::create([
            'order_id' => $order->id,
            'stripe_payment_intent_id' => 'pi_test_'.$order->id,
            'stripe_client_secret' => 'pi_test_secret',
            'amount' => $order->total,
            'currency' => 'usd',
            'status' => 'requires_payment_method',
        ]);

        return [$order, $payment];
    }

    public function test_declined_payment_marks_order_failed_and_restores_stock(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['price' => 10, 'stock' => 5]);
        [$order, $payment] = $this->pendingOrderWithPayment($user, $product);
        $this->assertEquals(3, $product->fresh()->stock);

        // Stripe simulado: la tarjeta es rechazada
        $this->mock(StripeService::class, function (MockInterface $mock) {
            $mock->shouldReceive('confirmPaymentIntent')->once()->andThrow(
                CardException::factory('Your card was declined.', 402, null, ['error' => ['code' => 'card_declined']], null, 'card_declined', 'generic_decline')
            );
        });

        $this->actingAs($user)->postJson("/api/payments/{$payment->id}/confirm", ['payment_method' => 'pm_card_chargeDeclined'])
            ->assertStatus(402)
            ->assertJsonPath('success', false);

        $this->assertEquals(Order::STATUS_FAILED, $order->fresh()->status);
        $this->assertEquals('failed', $payment->fresh()->status);
        $this->assertEquals(5, $product->fresh()->stock);
    }

    public function test_stock_is_restored_only_once(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['price' => 10, 'stock' => 5]);
        [$order] = $this->pendingOrderWithPayment($user, $product);

        // Primer rechazo (confirm) y segundo aviso (webhook) sobre la misma orden
        $this->assertTrue($order->markAsFailed());
        $this->assertFalse($order->markAsFailed());
        $this->assertFalse($order->markAsCancelled());

        $this->assertEquals(5, $product->fresh()->stock);
        $this->assertEquals(Order::STATUS_FAILED, $order->fresh()->status);
    }

    public function test_paid_order_does_not_restore_stock(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['price' => 10, 'stock' => 5]);
        [$order] = $this->pendingOrderWithPayment($user, $product);
        $order->update(['status' => Order::STATUS_PAID]);

        $this->assertFalse($order->markAsCancelled());
        $this->assertEquals(3, $product->fresh()->stock);
        $this->assertEquals(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_protected_route_without_token_returns_json_401(): void
    {
        // Swagger UI y curl envían "Accept: */*": antes esto daba 500 "Route [login] not defined"
        $this->withHeaders(['Accept' => '*/*'])->get('/api/orders')
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }
}
