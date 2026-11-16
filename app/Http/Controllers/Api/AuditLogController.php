<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class AuditLogController extends ApiController
{
    #[OA\Get(
        path: '/api/v1/admin/audit-logs',
        tags: ['Administración'],
        summary: 'Registro de auditoría de acciones administrativas',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'action', in: 'query', required: false, schema: new OA\Schema(type: 'string', example: 'product.updated')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', example: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Registros', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/AuditLog')),
                new OA\Property(property: 'meta', type: 'object'),
            ])),
            new OA\Response(response: 403, description: 'Requiere rol admin', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $action = mb_substr((string) $request->query('action', ''), 0, 50);

        $logs = AuditLog::with('user')
            ->when($action !== '', fn ($q) => $q->where('action', $action))
            ->latest('id')
            ->paginate(max(1, min((int) $request->query('per_page', 20), 100)));

        return response()->json([
            'success' => true,
            'message' => 'Registro de auditoría.',
            'data' => AuditLogResource::collection($logs->items()),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }
}
