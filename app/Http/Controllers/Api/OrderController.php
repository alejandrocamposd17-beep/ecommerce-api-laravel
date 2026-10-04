<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class OrderController extends ApiController
{
    /** Porcentaje de IVA aplicado (El Salvador: 13%). */
    public const TAX_RATE = 0.13;

    #[OA\Get(
        path: '/api/orders',
        tags: ['Órdenes'],
        summary: 'Historial de compras del usuario autenticado',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['pending', 'paid', 'failed', 'cancelled'])),
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
            ->paginate(min((int) $request->get('per_page', 15), 100));

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
        path: '/api/orders',
        tags: ['Órdenes'],
        summary: 'Crear una orden de compra',
        description: 'Valida el stock disponible, calcula subtotal + IVA (13%) y descuenta el inventario. La orden queda en estado pending hasta que se procese el pago.',
        security: [['bearerAuth' => []]],
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
            new OA\Response(response: 422, description: 'Error de validación', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function store(StoreOrderRequest $request): JsonResponse
    {
        $items = collect($request->validated('items'))
            ->groupBy('product_id')
            ->map(fn ($group) => $group->sum('quantity'));

        try {
            $order = DB::transaction(function () use ($request, $items) {
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

                $tax = round($subtotal * self::TAX_RATE, 2);

                $order = Order::create([
                    'user_id' => $request->user()->id,
                    'order_number' => Order::generateOrderNumber(),
                    'status' => Order::STATUS_PENDING,
                    'subtotal' => $subtotal,
                    'tax' => $tax,
                    'total' => round($subtotal + $tax, 2),
                    'currency' => 'usd',
                    'shipping_address' => $request->validated('shipping_address') ?? $request->user()->address,
                    'notes' => $request->validated('notes'),
                ]);

                $order->items()->createMany($lines);

                return $order;
            });
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 409);
        }

        return $this->success(new OrderResource($order->load('items')), 'Orden creada correctamente. Proceda al pago.', 201);
    }

    #[OA\Get(
        path: '/api/orders/{id}',
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
