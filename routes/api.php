<?php

use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CouponController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Middleware\DeprecatedApiVersion;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - E-commerce Seguro (Alejandro Campos)
|--------------------------------------------------------------------------
| Versión actual: /api/v1/*. Las rutas /api/* sin versión se mantienen por
| compatibilidad con clientes existentes y responden con el encabezado Deprecation.
*/

$routes = function () {
    // Rutas públicas
    Route::prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register'])->middleware('throttle:auth');
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth');
    });

    Route::get('products', [ProductController::class, 'index']);
    Route::get('products/{product}', [ProductController::class, 'show']);

    Route::post('stripe/webhook', [PaymentController::class, 'webhook']);

    // Rutas protegidas con Sanctum
    Route::middleware('auth:sanctum')->group(function () {
        Route::prefix('auth')->group(function () {
            Route::get('me', [AuthController::class, 'me']);
            Route::post('logout', [AuthController::class, 'logout']);
        });

        // Productos: crear, editar y eliminar requieren rol admin
        Route::post('products', [ProductController::class, 'store']);
        Route::match(['put', 'patch'], 'products/{product}', [ProductController::class, 'update']);
        Route::delete('products/{product}', [ProductController::class, 'destroy']);

        // Órdenes
        Route::get('orders', [OrderController::class, 'index']);
        Route::post('orders', [OrderController::class, 'store'])->middleware('throttle:checkout');
        Route::get('orders/{order}', [OrderController::class, 'show']);
        Route::post('orders/{order}/pay', [PaymentController::class, 'pay'])->middleware('throttle:checkout');

        // Pagos
        Route::get('payments/{payment}', [PaymentController::class, 'show']);
        Route::post('payments/{payment}/confirm', [PaymentController::class, 'confirm'])->middleware('throttle:checkout');

        // Administración (rol admin)
        Route::middleware('admin')->group(function () {
            Route::post('orders/{order}/refund', [PaymentController::class, 'refund']);

            Route::prefix('admin')->group(function () {
                Route::get('coupons', [CouponController::class, 'index']);
                Route::post('coupons', [CouponController::class, 'store']);
                Route::match(['put', 'patch'], 'coupons/{coupon}', [CouponController::class, 'update']);
                Route::delete('coupons/{coupon}', [CouponController::class, 'destroy']);
                Route::get('audit-logs', [AuditLogController::class, 'index']);
            });
        });
    });
};

Route::prefix('v1')->group($routes);

// Rutas sin versión (obsoletas): mismas acciones, con aviso de la versión sucesora
Route::middleware(DeprecatedApiVersion::class)->group($routes);
