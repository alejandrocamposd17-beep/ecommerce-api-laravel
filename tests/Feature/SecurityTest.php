<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Pruebas de las protecciones de seguridad de la API.
 */
class SecurityTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec_test_secret';

    /** Crea una orden pendiente de 2 unidades a $10 (total $22.60) con su intento de pago. */
    private function pendingOrder(User $user, Product $product): array
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

    /** Evento de Stripe firmado igual que lo hace Stripe (HMAC-SHA256 de "timestamp.payload"). */
    private function signedWebhook(array $intent, string $type = 'payment_intent.succeeded', ?string $eventId = null): array
    {
        $payload = json_encode([
            'id' => $eventId ?? 'evt_'.bin2hex(random_bytes(6)),
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => array_merge(['object' => 'payment_intent'], $intent)],
        ]);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", self::WEBHOOK_SECRET);

        return [$payload, "t={$timestamp},v1={$signature}"];
    }

    private function postWebhook(string $payload, string $signature)
    {
        return $this->call('POST', '/api/stripe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $signature,
        ], $payload);
    }

    // ---------- Webhook de Stripe ----------

    public function test_webhook_is_rejected_when_secret_is_not_configured(): void
    {
        config(['services.stripe.webhook_secret' => null]);
        [$payload, $signature] = $this->signedWebhook(['id' => 'pi_x', 'amount' => 100, 'currency' => 'usd', 'status' => 'succeeded']);

        $this->postWebhook($payload, $signature)->assertStatus(400);
    }

    public function test_webhook_with_invalid_signature_is_rejected(): void
    {
        config(['services.stripe.webhook_secret' => self::WEBHOOK_SECRET]);
        [$payload] = $this->signedWebhook(['id' => 'pi_x', 'amount' => 100, 'currency' => 'usd', 'status' => 'succeeded']);

        $this->postWebhook($payload, 't='.time().',v1=firma_falsa')->assertStatus(400);
    }

    public function test_valid_webhook_marks_order_as_paid(): void
    {
        config(['services.stripe.webhook_secret' => self::WEBHOOK_SECRET]);
        $user = User::factory()->create();
        $product = Product::factory()->create(['price' => 10, 'stock' => 5]);
        [$order, $payment] = $this->pendingOrder($user, $product);

        [$payload, $signature] = $this->signedWebhook([
            'id' => $payment->stripe_payment_intent_id,
            'amount' => 2260,
            'currency' => 'usd',
            'status' => 'succeeded',
            'metadata' => ['order_id' => (string) $order->id],
            'payment_method' => 'pm_test',
            'latest_charge' => 'ch_test',
        ]);

        $this->postWebhook($payload, $signature)->assertOk();

        $this->assertEquals(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertNotNull($payment->fresh()->paid_at);
    }

    public function test_webhook_with_wrong_amount_does_not_mark_order_as_paid(): void
    {
        config(['services.stripe.webhook_secret' => self::WEBHOOK_SECRET]);
        $user = User::factory()->create();
        $product = Product::factory()->create(['price' => 10, 'stock' => 5]);
        [$order, $payment] = $this->pendingOrder($user, $product);

        [$payload, $signature] = $this->signedWebhook([
            'id' => $payment->stripe_payment_intent_id,
            'amount' => 100, // $1.00 en lugar de $22.60
            'currency' => 'usd',
            'status' => 'succeeded',
            'metadata' => ['order_id' => (string) $order->id],
        ]);

        $this->postWebhook($payload, $signature)->assertOk();

        $this->assertEquals(Order::STATUS_PENDING, $order->fresh()->status);
    }

    public function test_payment_for_closed_order_does_not_reopen_it(): void
    {
        config(['services.stripe.webhook_secret' => self::WEBHOOK_SECRET]);
        $user = User::factory()->create();
        $product = Product::factory()->create(['price' => 10, 'stock' => 5]);
        [$order, $payment] = $this->pendingOrder($user, $product);
        $order->markAsCancelled(); // la orden expiró y liberó su stock

        [$payload, $signature] = $this->signedWebhook([
            'id' => $payment->stripe_payment_intent_id,
            'amount' => 2260,
            'currency' => 'usd',
            'status' => 'succeeded',
            'metadata' => ['order_id' => (string) $order->id],
        ]);

        $this->postWebhook($payload, $signature)->assertOk();

        $this->assertEquals(Order::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertEquals(5, $product->fresh()->stock);
    }

    // ---------- Pagos ----------

    public function test_server_side_confirmation_is_disabled_in_production(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['price' => 10, 'stock' => 5]);
        [, $payment] = $this->pendingOrder($user, $product);

        $this->app['env'] = 'production';

        $this->actingAs($user)->postJson("/api/payments/{$payment->id}/confirm")->assertForbidden();
    }

    public function test_cannot_confirm_payment_of_a_closed_order(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['price' => 10, 'stock' => 5]);
        [$order, $payment] = $this->pendingOrder($user, $product);
        $order->markAsFailed();

        $this->mock(StripeService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('confirmPaymentIntent'));

        $this->actingAs($user)->postJson("/api/payments/{$payment->id}/confirm")->assertStatus(409);
    }

    public function test_payment_method_must_look_like_a_stripe_id(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['price' => 10, 'stock' => 5]);
        [, $payment] = $this->pendingOrder($user, $product);

        $this->actingAs($user)->postJson("/api/payments/{$payment->id}/confirm", ['payment_method' => "'; DROP TABLE users; --"])
            ->assertStatus(422);
    }

    public function test_users_cannot_see_payments_or_orders_of_others(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $product = Product::factory()->create(['price' => 10, 'stock' => 5]);
        [$order, $payment] = $this->pendingOrder($owner, $product);

        $this->actingAs($intruder)->getJson("/api/orders/{$order->id}")->assertNotFound();
        $this->actingAs($intruder)->getJson("/api/payments/{$payment->id}")->assertNotFound();
        $this->actingAs($intruder)->postJson("/api/orders/{$order->id}/pay")->assertNotFound();
    }

    // ---------- Órdenes e inventario ----------

    public function test_pending_orders_per_user_are_limited(): void
    {
        config(['shop.max_pending_orders_per_user' => 2]);
        $user = User::factory()->create();
        $product = Product::factory()->create(['stock' => 50]);
        $body = ['items' => [['product_id' => $product->id, 'quantity' => 1]]];

        $this->actingAs($user)->postJson('/api/orders', $body)->assertCreated();
        $this->actingAs($user)->postJson('/api/orders', $body)->assertCreated();
        $this->actingAs($user)->postJson('/api/orders', $body)->assertStatus(409);
    }

    public function test_expired_pending_orders_release_stock(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['price' => 10, 'stock' => 5]);
        [$order, $payment] = $this->pendingOrder($user, $product);
        $this->assertEquals(3, $product->fresh()->stock);

        $this->mock(StripeService::class, function (MockInterface $mock) {
            $mock->shouldReceive('cancelPaymentIntent')->once()->andReturnUsing(
                fn (Payment $p) => $p->update(['status' => 'canceled'])
            );
        });

        $this->travel(31)->minutes();
        $this->artisan('orders:expire-pending')->assertSuccessful();

        $this->assertEquals(Order::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertEquals('canceled', $payment->fresh()->status);
        $this->assertEquals(5, $product->fresh()->stock);
    }

    public function test_recent_pending_orders_are_not_expired(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['price' => 10, 'stock' => 5]);
        [$order] = $this->pendingOrder($user, $product);

        $this->artisan('orders:expire-pending')->assertSuccessful();

        $this->assertEquals(Order::STATUS_PENDING, $order->fresh()->status);
    }

    // ---------- Productos ----------

    public function test_inactive_products_are_hidden_from_the_public(): void
    {
        $product = Product::factory()->create(['is_active' => false]);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->getJson("/api/products/{$product->id}")->assertNotFound();
        $this->actingAs($admin)->getJson("/api/products/{$product->id}")->assertOk();
    }

    public function test_invalid_pagination_values_are_clamped(): void
    {
        Product::factory()->count(3)->create();

        $this->getJson('/api/products?per_page=0')->assertOk()->assertJsonPath('meta.per_page', 1);
        $this->getJson('/api/products?per_page=5000')->assertOk()->assertJsonPath('meta.per_page', 100);
    }

    public function test_customers_cannot_update_or_delete_products(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $product = Product::factory()->create();

        $this->actingAs($customer)->putJson("/api/products/{$product->id}", ['price' => 0.01])->assertForbidden();
        $this->actingAs($customer)->deleteJson("/api/products/{$product->id}")->assertForbidden();
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    // ---------- Autenticación y cabeceras ----------

    public function test_registration_cannot_escalate_role_to_admin(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Intruso',
            'email' => 'intruso@test.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'admin',
        ])->assertCreated()->assertJsonPath('data.user.role', 'customer');
    }

    public function test_login_is_rate_limited(): void
    {
        $user = User::factory()->create(['password' => 'password123']);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'incorrecta'])->assertStatus(401);
        }

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'incorrecta'])->assertStatus(429);
    }

    public function test_logout_revokes_the_token(): void
    {
        $user = User::factory()->create(['password' => 'password123']);
        $token = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password123'])->json('data.token');

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_api_responses_include_security_headers(): void
    {
        $this->getJson('/api/products')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer');
    }
}
