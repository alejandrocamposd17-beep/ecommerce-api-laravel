<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'API de E-commerce Segura',
    description: 'API REST para un e-commerce básico desarrollada con Laravel 12. Incluye autenticación con Laravel Sanctum, catálogo de productos, órdenes de compra y pagos con Stripe. Autor: Alejandro Campos.',
    contact: new OA\Contact(name: 'Alejandro Campos')
)]
#[OA\Server(url: L5_SWAGGER_CONST_HOST, description: 'Servidor de la API')]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'Sanctum Token',
    description: 'Ingrese el token obtenido en /api/auth/login o /api/auth/register'
)]
#[OA\Tag(name: 'Autenticación', description: 'Registro, inicio y cierre de sesión')]
#[OA\Tag(name: 'Productos', description: 'Catálogo de productos (CRUD)')]
#[OA\Tag(name: 'Órdenes', description: 'Órdenes de compra e historial')]
#[OA\Tag(name: 'Pagos', description: 'Procesamiento de pagos con Stripe')]
#[OA\Schema(
    schema: 'ErrorResponse',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: false),
        new OA\Property(property: 'message', type: 'string', example: 'Descripción del error'),
        new OA\Property(property: 'errors', type: 'object', nullable: true, example: ['email' => ['El campo email es obligatorio.']]),
    ]
)]
#[OA\Schema(
    schema: 'User',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'name', type: 'string', example: 'Alejandro Campos'),
        new OA\Property(property: 'email', type: 'string', example: 'alejandro@example.com'),
        new OA\Property(property: 'role', type: 'string', enum: ['admin', 'customer'], example: 'customer'),
        new OA\Property(property: 'phone', type: 'string', nullable: true, example: '+503 7000-0000'),
        new OA\Property(property: 'address', type: 'string', nullable: true, example: 'San Salvador, El Salvador'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'Product',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'name', type: 'string', example: 'Laptop Lenovo IdeaPad 3'),
        new OA\Property(property: 'slug', type: 'string', example: 'laptop-lenovo-ideapad-3-a1b2c'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'price', type: 'number', format: 'float', example: 549.99),
        new OA\Property(property: 'stock', type: 'integer', example: 15),
        new OA\Property(property: 'category', type: 'string', nullable: true, example: 'Computadoras'),
        new OA\Property(property: 'image_url', type: 'string', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean', example: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'OrderItem',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'product_id', type: 'integer', example: 1),
        new OA\Property(property: 'product_name', type: 'string', example: 'Laptop Lenovo IdeaPad 3'),
        new OA\Property(property: 'quantity', type: 'integer', example: 2),
        new OA\Property(property: 'unit_price', type: 'number', format: 'float', example: 549.99),
        new OA\Property(property: 'subtotal', type: 'number', format: 'float', example: 1099.98),
    ]
)]
#[OA\Schema(
    schema: 'Payment',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'order_id', type: 'integer', example: 1),
        new OA\Property(property: 'stripe_payment_intent_id', type: 'string', example: 'pi_3Nx...'),
        new OA\Property(property: 'client_secret', type: 'string', nullable: true, description: 'Se usa en el frontend con Stripe.js para completar el pago'),
        new OA\Property(property: 'amount', type: 'number', format: 'float', example: 1242.98),
        new OA\Property(property: 'currency', type: 'string', example: 'usd'),
        new OA\Property(property: 'status', type: 'string', example: 'requires_payment_method'),
        new OA\Property(property: 'payment_method', type: 'string', nullable: true),
        new OA\Property(property: 'paid_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'Order',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'order_number', type: 'string', example: 'ORD-20250115-A1B2C3'),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'paid', 'failed', 'cancelled'], example: 'pending'),
        new OA\Property(property: 'subtotal', type: 'number', format: 'float', example: 1099.98),
        new OA\Property(property: 'tax', type: 'number', format: 'float', example: 143.00),
        new OA\Property(property: 'total', type: 'number', format: 'float', example: 1242.98),
        new OA\Property(property: 'currency', type: 'string', example: 'usd'),
        new OA\Property(property: 'shipping_address', type: 'string', nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/OrderItem')),
        new OA\Property(property: 'payment', ref: '#/components/schemas/Payment', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
abstract class ApiController extends Controller
{
    protected function success(mixed $data = null, string $message = 'Operación exitosa', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    protected function error(string $message, int $status = 400, mixed $errors = null): JsonResponse
    {
        $payload = ['success' => false, 'message' => $message];
        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }
}
