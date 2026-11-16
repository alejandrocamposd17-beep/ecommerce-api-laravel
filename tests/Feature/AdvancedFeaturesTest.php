<?php

namespace Tests\Feature;

use App\Mail\OrderPlaced;
use App\Mail\OrderRefunded;
use App\Mail\PaymentReceived;
use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\StripeEvent;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Versionado, idempotencia (webhook y órdenes), cupones, reembolsos, correos y auditoría.
 */
class AdvancedFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['services.stripe.webhook_secret' => self::WEBHOOK_SECRET]);
    }

    /** @param  array<string, mixed>  $extra */
    private function createOrder(User $user, Product $product, int $quantity = 2, array $extra = [], array $headers = [])
    {
        return $this->actingAs($user)->withHeaders($headers)->postJson('/api/v1/orders', array_merge([
            'items' => [['product_id' => $product->id, 'quantity' => $quantity]],
        ], $extra));
    }

    /** Orden de $20 (2 x $10) ya pagada, con su pago exitoso. */
    private function paidOrder(User $user, Product $product): array
    {
        $order = Order::findOrFail($this->createOrder($user, $product)->assertCreated()->json('data.id'));
        $order->update(['status' => Order::STATUS_PAID]);
        $payment = Payment::create([
            'order_id' => $order->id,
            'stripe_payment_intent_id' => 'pi_paid_'.$order->id,
            'stripe_client_secret' => 'secret',
            'amount' => $order->total,
            'currency' => 'usd',
            'status' => 'succeeded',
            'paid_at' => now(),
        ]);

        return [$order, $payment];
    }

    private function postSignedEvent(string $eventId, string $type, array $object)
    {
        $payload = json_encode(['id' => $eventId, 'object' => 'event', 'type' => $type, 'data' => ['object' => $object]]);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", self::WEBHOOK_SECRET);

        return $this->call('POST', '/api/v1/stripe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $payload);
    }

    // ---------- Versionado ----------

    public function test_v1_routes_work_without_deprecation_header(): void
    {
        Product::factory()->count(2)->create();

        $response = $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(2, 'data');
        $this->assertFalse($response->headers->has('Deprecation'));
    }

    public function test_unversioned_routes_still_work_but_are_marked_deprecated(): void
    {
        Product::factory()->create();

        $this->getJson('/api/products')
            ->assertOk()
            ->assertHeader('Deprecation', 'true')
            ->assertHeader('Link', '<'.url('/api/v1/products').'>; rel="successor-version"');
    }

    // ---------- Webhook idempotente ----------

    public function test_repeated_webhook_event_is_processed_only_once(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['price' => 10, 'stock' => 5]);
        $order = Order::findOrFail($this->createOrder($user, $product)->json('data.id'));
        $payment = Payment::create([
            'order_id' => $order->id, 'stripe_payment_intent_id' => 'pi_dup', 'stripe_client_secret' => 's',
            'amount' => $order->total, 'currency' => 'usd', 'status' => 'requires_payment_method',
        ]);

        $intent = [
            'id' => 'pi_dup', 'object' => 'payment_intent', 'amount' => 2260, 'currency' => 'usd',
            'status' => 'succeeded', 'metadata' => ['order_id' => (string) $order->id],
        ];

        $this->postSignedEvent('evt_dup', 'payment_intent.succeeded', $intent)->assertOk()->assertJsonMissingPath('data.duplicate');
        $this->postSignedEvent('evt_dup', 'payment_intent.succeeded', $intent)->assertOk()->assertJsonPath('data.duplicate', true);

        $this->assertSame(1, StripeEvent::where('event_id', 'evt_dup')->count());
        $this->assertEquals(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertNotNull($payment->fresh()->paid_at);
        Mail::assertQueued(PaymentReceived::class, 1);
    }

    public function test_charge_refunded_webhook_marks_order_refunded_and_restores_stock(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['price' => 10, 'stock' => 5]);
        [$order, $payment] = $this->paidOrder($user, $product);
        $this->assertEquals(3, $product->fresh()->stock);

        $charge = ['id' => 'ch_1', 'object' => 'charge', 'payment_intent' => $payment->stripe_payment_intent_id, 'refunded' => true];
        $this->postSignedEvent('evt_refund', 'charge.refunded', $charge)->assertOk();
        // Reenvío del mismo evento: no repone el stock dos veces
        $this->postSignedEvent('evt_refund', 'charge.refunded', $charge)->assertOk();

        $this->assertEquals(Order::STATUS_REFUNDED, $order->fresh()->status);
        $this->assertEquals('refunded', $payment->fresh()->status);
        $this->assertEquals(5, $product->fresh()->stock);
        Mail::assertQueued(OrderRefunded::class, 1);
    }

    // ---------- Idempotency-Key en órdenes ----------

    public function test_retried_order_with_same_idempotency_key_returns_the_same_order(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['price' => 10, 'stock' => 5]);
        $headers = ['Idempotency-Key' => 'compra-123456'];

        $first = $this->createOrder($user, $product, 2, [], $headers)->assertCreated();
        $second = $this->createOrder($user, $product, 2, [], $headers)
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Order::count());
        $this->assertEquals(3, $product->fresh()->stock);
        Mail::assertQueued(OrderPlaced::class, 1);
    }

    public function test_same_idempotency_key_with_different_data_is_rejected(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['price' => 10, 'stock' => 5]);
        $headers = ['Idempotency-Key' => 'compra-abcdefgh'];

        $this->createOrder($user, $product, 1, [], $headers)->assertCreated();
        $this->createOrder($user, $product, 3, [], $headers)->assertStatus(422);

        $this->assertSame(1, Order::count());
    }

    public function test_idempotency_key_is_scoped_to_each_user(): void
    {
        $product = Product::factory()->create(['price' => 10, 'stock' => 5]);
        $headers = ['Idempotency-Key' => 'misma-llave-01'];

        $this->createOrder(User::factory()->create(), $product, 1, [], $headers)->assertCreated();
        $this->createOrder(User::factory()->create(), $product, 1, [], $headers)->assertCreated();

        $this->assertSame(2, Order::count());
    }

    public function test_invalid_idempotency_key_is_rejected(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();

        $this->createOrder($user, $product, 1, [], ['Idempotency-Key' => 'corta'])->assertStatus(422);
        $this->createOrder($user, $product, 1, [], ['Idempotency-Key' => 'tiene espacios!!'])->assertStatus(422);
    }

    // ---------- Cupones ----------

    public function test_percent_coupon_is_applied_before_tax(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['price' => 10, 'stock' => 5]);
        $coupon = Coupon::create(['code' => 'DESC10', 'type' => 'percent', 'value' => 10]);

        // Subtotal 20 - descuento 2 = 18; IVA 13% de 18 = 2.34; total 20.34
        $this->createOrder($user, $product, 2, ['coupon_code' => 'desc10'])
            ->assertCreated()
            ->assertJsonPath('data.subtotal', 20)
            ->assertJsonPath('data.discount', 2)
            ->assertJsonPath('data.tax', 2.34)
            ->assertJsonPath('data.total', 20.34);

        $this->assertSame(1, $coupon->fresh()->used_count);
    }

    public function test_fixed_coupon_never_exceeds_subtotal(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['price' => 10, 'stock' => 5]);
        Coupon::create(['code' => 'REGALO', 'type' => 'fixed', 'value' => 500]);

        $this->createOrder($user, $product, 1, ['coupon_code' => 'REGALO'])
            ->assertCreated()
            ->assertJsonPath('data.discount', 10)
            ->assertJsonPath('data.total', 0);
    }

    public function test_unusable_coupons_are_rejected_and_stock_is_untouched(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['price' => 10, 'stock' => 5]);
        Coupon::create(['code' => 'VENCIDO', 'type' => 'percent', 'value' => 10, 'expires_at' => now()->subDay()]);
        Coupon::create(['code' => 'AGOTADO', 'type' => 'percent', 'value' => 10, 'max_uses' => 1, 'used_count' => 1]);
        Coupon::create(['code' => 'INACTIVO', 'type' => 'percent', 'value' => 10, 'is_active' => false]);
        Coupon::create(['code' => 'MINIMO', 'type' => 'fixed', 'value' => 5, 'min_subtotal' => 100]);

        foreach (['VENCIDO', 'AGOTADO', 'INACTIVO', 'MINIMO', 'NOEXISTE'] as $code) {
            $this->createOrder($user, $product, 1, ['coupon_code' => $code])->assertStatus(422);
        }

        $this->assertSame(0, Order::count());
        $this->assertEquals(5, $product->fresh()->stock);
    }

    public function test_coupon_use_is_released_when_order_is_cancelled(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['price' => 10, 'stock' => 5]);
        $coupon = Coupon::create(['code' => 'UNAVEZ', 'type' => 'percent', 'value' => 10, 'max_uses' => 1]);

        $orderId = $this->createOrder($user, $product, 1, ['coupon_code' => 'UNAVEZ'])->assertCreated()->json('data.id');
        $this->assertSame(1, $coupon->fresh()->used_count);

        Order::findOrFail($orderId)->markAsCancelled();

        $this->assertSame(0, $coupon->fresh()->used_count);
        $this->createOrder($user, $product, 1, ['coupon_code' => 'UNAVEZ'])->assertCreated();
    }

    public function test_only_admins_can_manage_coupons(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $admin = User::factory()->create(['role' => 'admin']);
        $data = ['code' => 'verano25', 'type' => 'percent', 'value' => 25, 'max_uses' => 10];

        $this->actingAs($customer)->postJson('/api/v1/admin/coupons', $data)->assertForbidden();
        $this->actingAs($customer)->getJson('/api/v1/admin/coupons')->assertForbidden();

        $id = $this->actingAs($admin)->postJson('/api/v1/admin/coupons', $data)
            ->assertCreated()
            ->assertJsonPath('data.code', 'VERANO25')
            ->json('data.id');

        $this->actingAs($admin)->patchJson("/api/v1/admin/coupons/{$id}", ['value' => 30])
            ->assertOk()
            ->assertJsonPath('data.value', 30);

        $this->actingAs($admin)->postJson('/api/v1/admin/coupons', ['code' => 'MAL', 'type' => 'percent', 'value' => 150])
            ->assertStatus(422);

        $this->actingAs($admin)->deleteJson("/api/v1/admin/coupons/{$id}")->assertOk();
        $this->assertDatabaseMissing('coupons', ['id' => $id]);
    }

    // ---------- Reembolsos ----------

    public function test_admin_can_refund_a_paid_order(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = User::factory()->create();
        $product = Product::factory()->create(['price' => 10, 'stock' => 5]);
        [$order, $payment] = $this->paidOrder($customer, $product);

        $this->partialMock(StripeService::class, function (MockInterface $mock) {
            $mock->shouldReceive('refundPayment')->once()->andReturnUsing(
                fn (Payment $payment) => $mock->markRefunded($payment, 're_test_123')
            );
        });

        $this->actingAs($customer)->postJson("/api/v1/orders/{$order->id}/refund")->assertForbidden();

        $this->actingAs($admin)->postJson("/api/v1/orders/{$order->id}/refund")
            ->assertOk()
            ->assertJsonPath('data.status', 'refunded')
            ->assertJsonMissingPath('data.client_secret');

        $this->assertEquals(Order::STATUS_REFUNDED, $order->fresh()->status);
        $this->assertEquals('re_test_123', $payment->fresh()->stripe_refund_id);
        $this->assertEquals(5, $product->fresh()->stock);
        $this->assertDatabaseHas('audit_logs', ['action' => 'order.refunded', 'subject_id' => $order->id, 'user_id' => $admin->id]);
        Mail::assertQueued(OrderRefunded::class, fn (OrderRefunded $mail) => $mail->hasTo($customer->email));
    }

    public function test_pending_order_cannot_be_refunded(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::factory()->create(['price' => 10, 'stock' => 5]);
        $orderId = $this->createOrder(User::factory()->create(), $product)->json('data.id');

        $this->actingAs($admin)->postJson("/api/v1/orders/{$orderId}/refund")->assertStatus(409);
    }

    // ---------- Correos y auditoría ----------

    public function test_order_confirmation_email_is_queued_to_the_customer(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['price' => 10, 'stock' => 5]);

        $this->createOrder($user, $product)->assertCreated();

        Mail::assertQueued(OrderPlaced::class, fn (OrderPlaced $mail) => $mail->hasTo($user->email));
    }

    public function test_order_email_renders_order_details(): void
    {
        $user = User::factory()->create(['name' => 'Cliente Demo']);
        $product = Product::factory()->create(['name' => 'Teclado mecánico', 'price' => 10, 'stock' => 5]);
        $order = Order::findOrFail($this->createOrder($user, $product)->json('data.id'));

        $html = (new OrderPlaced($order))->render();

        $this->assertStringContainsString('Cliente Demo', $html);
        $this->assertStringContainsString('Teclado mecánico', $html);
        $this->assertStringContainsString($order->order_number, $html);
    }

    public function test_admin_changes_are_written_to_the_audit_log(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = User::factory()->create(['role' => 'customer']);
        $product = Product::factory()->create(['price' => 100]);

        $this->actingAs($admin)->patchJson("/api/v1/products/{$product->id}", ['price' => 80])->assertOk();

        $log = AuditLog::where('action', 'product.updated')->firstOrFail();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertEquals(80, $log->changes['price']['new']);
        $this->assertArrayNotHasKey('updated_at', $log->changes);

        $this->actingAs($customer)->getJson('/api/v1/admin/audit-logs')->assertForbidden();
        $this->actingAs($admin)->getJson('/api/v1/admin/audit-logs?action=product.updated')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user.id', $admin->id);
    }
}
