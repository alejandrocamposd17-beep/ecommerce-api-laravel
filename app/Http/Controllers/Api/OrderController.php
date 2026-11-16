<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InvalidCouponException;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Mail\OrderPlaced;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use OpenApi\Attributes as OA;

class OrderController extends ApiController
{
    /** IVA por defecto (El Salvador: 13%); el valor real se lee de config('shop.tax_rate'). */
    public const TAX_RATE = 0.13;

    #[OA\Get(
        path: '/api/v1/orders',
        tags: ['Órdenes'],
        summary: 'Historial de compras del usuario autenticado',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['pending', 'paid', 'failed', 'cancelled', 'refunded'])),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', example: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Historial de órdenes', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Order')),
                new OA\Property(property: 'meta', type: 'object'),
            ])),
            new OA\Response(response: 401, description: 'No autenticado', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $orders = $request->user()->orders()
            ->with(['items', 'payment'])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate(max(1, min((int) $request->get('per_page', 15), 100)));

        return response()->json([
            'success' => true,
            'message' => 'Historial de compras.',
            'data' => OrderResource::collection($orders->items()),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/orders',
        tags: ['Órdenes'],
        summary: 'Crear una orden de compra',
        description: 'Valida el stock disponible, aplica el cupón (opcional), calcula subtotal - descuento + IVA (13%) y descuenta el inventario. La orden queda en estado pending hasta que se procese el pago. Enviar el encabezado Idempotency-Key permite reintentar sin crear órdenes duplicadas.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, description: 'Llave única por intento de compra (8-100 caracteres). Si se repite, devuelve la misma orden.', schema: new OA\Schema(type: 'string', example: 'compra-7f3c9a12')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['items'],
            properties: [
                new OA\Property(property: 'items', type: 'array', items: new OA\Items(
                    required: ['product_id', 'quantity'],
                    properties: [
                        new OA\Property(property: 'product_id', type: 'integer', example: 1),
                        new OA\Property(property: 'quantity', type: 'integer', example: 2),
                    ]
                )),
                new OA\Property(property: 'shipping_address', type: 'string', nullable: true, example: 'Col. Escalón, San Salvador'),
                new OA\Property(property: 'notes', type: 'string', nullable: true, example: 'Entregar en horario de oficina'),
                new OA\Property(property: 'coupon_code', type: 'string', nullable: true, example: 'BIENVENIDA10'),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Orden creada', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', ref: '#/components/schemas/Order'),
            ])),
            new OA\Response(response: 401, description: 'No autenticado', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'Stock insuficiente o producto inactivo', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 200, description: 'Petición repetida con la misma Idempotency-Key: devuelve la orden ya creada'),
            new OA\Response(response: 422, description: 'Error de validación o cupón no válido', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function store(StoreOrderRequest $request): JsonResponse
    {
        $user = $request->user();

        // Idempotency-Key: si el cliente reintenta la misma petición (p. ej. por un corte de red),
        // se devuelve la orden ya creada en lugar de crear otra y reservar el stock dos veces.
        $idempotencyKey = $request->header('Idempotency-Key');
        $idempotencyHash = null;
        if ($idempotencyKey !== null) {
            if (! preg_match('/^[A-Za-z0-9_-]{8,100}$/', $idempotencyKey)) {
                return $this->error('El encabezado Idempotency-Key debe tener entre 8 y 100 caracteres (letras, números, - o _).', 422);
            }
            $idempotencyHash = hash('sha256', (string) json_encode($request->validated()));

            if ($replay = $this->replayIdempotentOrder($user->id, $idempotencyKey, $idempotencyHash)) {
                return $replay;
            }
        }

        // Evita que un usuario acapare inventario con muchas órdenes sin pagar
        $pending = $user->orders()->where('status', Order::STATUS_PENDING)->count();
        if ($pending >= config('shop.max_pending_orders_per_user', 5)) {
            return $this->error('Tiene demasiadas órdenes pendientes de pago. Pague o espere a que expiren antes de crear otra.', 409);
        }

        $items = collect($request->validated('items'))
            ->groupBy('product_id')
            ->map(fn ($group) => $group->sum('quantity'));

        try {
            $order = DB::transaction(function () use ($request, $user, $items, $idempotencyKey, $idempotencyHash) {
                $products = Product::whereIn('id', $items->keys())->lockForUpdate()->get()->keyBy('id');
                $subtotal = 0;
                $lines = [];

                foreach ($items as $productId => $quantity) {
                    $product = $products[$productId];

                    if (! $product->is_active) {
                        throw new \DomainException("El producto '{$product->name}' no está disponible.");
                    }
                    if ($product->stock < $quantity) {
                        throw new \DomainException("Stock insuficiente para '{$product->name}'. Disponible: {$product->stock}.");
                    }

                    $lineSubtotal = round($product->price * $quantity, 2);
                    $subtotal += $lineSubtotal;
                    $lines[] = [
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'quantity' => $quantity,
                        'unit_price' => $product->price,
                        'subtotal' => $lineSubtotal,
                    ];

                    $product->decrement('stock', $quantity);
                }

                [$coupon, $discount] = $this->applyCoupon($request->validated('coupon_code'), $subtotal);

                // El IVA se calcula sobre el subtotal ya descontado
                $taxable = round($subtotal - $discount, 2);
                $tax = round($taxable * (float) config('shop.tax_rate', self::TAX_RATE), 2);

                $order = Order::create([
                    'user_id' => $user->id,
                    'order_number' => Order::generateOrderNumber(),
                    'idempotency_key' => $idempotencyKey,
                    'idempotency_hash' => $idempotencyHash,
                    'status' => Order::STATUS_PENDING,
                    'subtotal' => $subtotal,
                    'discount' => $discount,
                    'coupon_id' => $coupon?->id,
                    'tax' => $tax,
                    'total' => round($taxable + $tax, 2),
                    'currency' => 'usd',
                    'shipping_address' => $request->validated('shipping_address') ?? $user->address,
                    'notes' => $request->validated('notes'),
                ]);

                $order->items()->createMany($lines);

                return $order;
            });
        } catch (InvalidCouponException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 409);
        } catch (UniqueConstraintViolationException $e) {
            // Dos peticiones con la misma Idempotency-Key llegaron a la vez: gana la primera
            if ($idempotencyKey !== null && $replay = $this->replayIdempotentOrder($user->id, $idempotencyKey, (string) $idempotencyHash)) {
                return $replay;
            }
            throw $e;
        }

        Mail::to($user)->send(new OrderPlaced($order));

        return $this->success(new OrderResource($order->load('items')), 'Orden creada correctamente. Proceda al pago.', 201);
    }

    /**
     * Valida y reserva el cupón dentro de la transacción (con bloqueo de fila para que dos
     * órdenes simultáneas no superen el límite de usos).
     *
     * @return array{0: Coupon|null, 1: float}
     */
    protected function applyCoupon(?string $code, float $subtotal): array
    {
        if ($code === null || $code === '') {
            return [null, 0.0];
        }

        $coupon = Coupon::where('code', strtoupper(trim($code)))->lockForUpdate()->first();
        if (! $coupon) {
            throw new InvalidCouponException('El cupón no existe.');
        }
        if ($reason = $coupon->unavailableReason($subtotal)) {
            throw new InvalidCouponException($reason);
        }

        $coupon->increment('used_count');

        return [$coupon, $coupon->discountFor($subtotal)];
    }

    /** Respuesta para una petición repetida con la misma Idempotency-Key, o null si es nueva. */
    protected function replayIdempotentOrder(int $userId, string $key, string $hash): ?JsonResponse
    {
        $order = Order::where('user_id', $userId)->where('idempotency_key', $key)->first();
        if (! $order) {
            return null;
        }

        // La misma llave con otros datos es un error del cliente, no un reintento
        if (! hash_equals((string) $order->idempotency_hash, $hash)) {
            return $this->error('Esta Idempotency-Key ya se usó con datos diferentes.', 422);
        }

        return $this->success(new OrderResource($order->load('items')), 'Orden ya creada anteriormente (petición repetida).')
            ->header('Idempotent-Replayed', 'true');
    }

    #[OA\Get(
        path: '/api/v1/orders/{id}',
        tags: ['Órdenes'],
        summary: 'Ver detalle de una orden propia',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Orden', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', ref: '#/components/schemas/Order'),
            ])),
            new OA\Response(response: 401, description: 'No autenticado', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'No encontrada', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function show(Request $request, Order $order): JsonResponse
    {
        if ($order->user_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            return $this->error('El recurso solicitado no existe.', 404);
        }

        return $this->success(new OrderResource($order->load(['items', 'payment'])), 'Detalle de la orden.');
    }
}
