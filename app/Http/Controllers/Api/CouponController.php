<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\CouponRequest;
use App\Http\Resources\CouponResource;
use App\Models\AuditLog;
use App\Models\Coupon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/** Administración de cupones de descuento (solo rol admin). */
class CouponController extends ApiController
{
    #[OA\Get(
        path: '/api/v1/admin/coupons',
        tags: ['Administración'],
        summary: 'Listar cupones',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Cupones', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Coupon')),
            ])),
            new OA\Response(response: 403, description: 'Requiere rol admin', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function index(): JsonResponse
    {
        return $this->success(CouponResource::collection(Coupon::latest('id')->limit(200)->get()), 'Cupones.');
    }

    #[OA\Post(
        path: '/api/v1/admin/coupons',
        tags: ['Administración'],
        summary: 'Crear cupón',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['code', 'type', 'value'],
            properties: [
                new OA\Property(property: 'code', type: 'string', example: 'BIENVENIDA10'),
                new OA\Property(property: 'type', type: 'string', enum: ['percent', 'fixed'], example: 'percent'),
                new OA\Property(property: 'value', type: 'number', example: 10),
                new OA\Property(property: 'min_subtotal', type: 'number', nullable: true, example: 50),
                new OA\Property(property: 'max_uses', type: 'integer', nullable: true, example: 100),
                new OA\Property(property: 'expires_at', type: 'string', format: 'date-time', nullable: true, example: '2027-12-31T23:59:59Z'),
                new OA\Property(property: 'is_active', type: 'boolean', example: true),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Cupón creado', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', ref: '#/components/schemas/Coupon'),
            ])),
            new OA\Response(response: 403, description: 'Requiere rol admin', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Error de validación', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function store(CouponRequest $request): JsonResponse
    {
        $coupon = Coupon::create($request->validated());
        AuditLog::record($request, 'coupon.created', $coupon, $request->validated());

        return $this->success(new CouponResource($coupon->fresh()), 'Cupón creado correctamente.', 201);
    }

    #[OA\Put(
        path: '/api/v1/admin/coupons/{id}',
        tags: ['Administración'],
        summary: 'Actualizar cupón',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/Coupon')),
        responses: [
            new OA\Response(response: 200, description: 'Cupón actualizado'),
            new OA\Response(response: 403, description: 'Requiere rol admin', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Error de validación', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function update(CouponRequest $request, Coupon $coupon): JsonResponse
    {
        $original = $coupon->getAttributes();
        $coupon->update($request->validated());
        AuditLog::record($request, 'coupon.updated', $coupon, AuditLog::diff($original, $coupon));

        return $this->success(new CouponResource($coupon->fresh()), 'Cupón actualizado correctamente.');
    }

    #[OA\Delete(
        path: '/api/v1/admin/coupons/{id}',
        tags: ['Administración'],
        summary: 'Eliminar cupón (si ya se usó, se desactiva)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Cupón eliminado o desactivado'),
            new OA\Response(response: 403, description: 'Requiere rol admin', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function destroy(Request $request, Coupon $coupon): JsonResponse
    {
        // Se conserva si alguna orden lo usó, para no perder el historial del descuento
        if ($coupon->orders()->exists()) {
            $coupon->update(['is_active' => false]);
            AuditLog::record($request, 'coupon.deactivated', $coupon);

            return $this->success(new CouponResource($coupon), 'El cupón ya se usó en órdenes: se desactivó en lugar de eliminarse.');
        }

        AuditLog::record($request, 'coupon.deleted', $coupon, ['code' => $coupon->code]);
        $coupon->delete();

        return $this->success(null, 'Cupón eliminado correctamente.');
    }
}
