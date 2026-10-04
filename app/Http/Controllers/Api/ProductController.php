<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ProductController extends ApiController
{
    #[OA\Get(
        path: '/api/products',
        tags: ['Productos'],
        summary: 'Listado público de productos (paginado)',
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Buscar por nombre o descripción', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'category', in: 'query', required: false, description: 'Filtrar por categoría', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Registros por página (default 15)', schema: new OA\Schema(type: 'integer', example: 15)),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', example: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lista de productos', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Product')),
                new OA\Property(property: 'meta', type: 'object'),
            ])),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $products = Product::query()
            ->where('is_active', true)
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('description', 'like', "%{$s}%")))
            ->when($request->category, fn ($q, $c) => $q->where('category', $c))
            ->orderBy('name')
            ->paginate(min((int) $request->get('per_page', 15), 100));

        return response()->json([
            'success' => true,
            'message' => 'Listado de productos.',
            'data' => ProductResource::collection($products->items()),
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/products/{id}',
        tags: ['Productos'],
        summary: 'Ver detalle de un producto',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Producto', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', ref: '#/components/schemas/Product'),
            ])),
            new OA\Response(response: 404, description: 'No encontrado', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function show(Product $product): JsonResponse
    {
        return $this->success(new ProductResource($product), 'Detalle del producto.');
    }

    #[OA\Post(
        path: '/api/products',
        tags: ['Productos'],
        summary: 'Crear producto (requiere rol admin)',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['name', 'price', 'stock'],
            properties: [
                new OA\Property(property: 'name', type: 'string', example: 'Mouse inalámbrico Logitech M170'),
                new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Mouse compacto con conexión USB 2.4 GHz'),
                new OA\Property(property: 'price', type: 'number', format: 'float', example: 14.99),
                new OA\Property(property: 'stock', type: 'integer', example: 50),
                new OA\Property(property: 'category', type: 'string', nullable: true, example: 'Accesorios'),
                new OA\Property(property: 'image_url', type: 'string', nullable: true, example: 'https://example.com/mouse.jpg'),
                new OA\Property(property: 'is_active', type: 'boolean', example: true),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Producto creado'),
            new OA\Response(response: 401, description: 'No autenticado', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Sin permisos (solo admin)', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Error de validación', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = Product::create($request->validated());

        return $this->success(new ProductResource($product), 'Producto creado correctamente.', 201);
    }

    #[OA\Put(
        path: '/api/products/{id}',
        tags: ['Productos'],
        summary: 'Actualizar producto (requiere rol admin)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'name', type: 'string', example: 'Mouse inalámbrico Logitech M170'),
                new OA\Property(property: 'description', type: 'string', nullable: true),
                new OA\Property(property: 'price', type: 'number', format: 'float', example: 12.99),
                new OA\Property(property: 'stock', type: 'integer', example: 40),
                new OA\Property(property: 'category', type: 'string', nullable: true),
                new OA\Property(property: 'image_url', type: 'string', nullable: true),
                new OA\Property(property: 'is_active', type: 'boolean'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Producto actualizado'),
            new OA\Response(response: 401, description: 'No autenticado', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Sin permisos (solo admin)', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'No encontrado', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Error de validación', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $product->update($request->validated());

        return $this->success(new ProductResource($product->fresh()), 'Producto actualizado correctamente.');
    }

    #[OA\Delete(
        path: '/api/products/{id}',
        tags: ['Productos'],
        summary: 'Eliminar producto (requiere rol admin)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Producto eliminado'),
            new OA\Response(response: 401, description: 'No autenticado', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Sin permisos (solo admin)', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'No encontrado', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'El producto tiene órdenes asociadas', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function destroy(Request $request, Product $product): JsonResponse
    {
        if (! $request->user()->isAdmin()) {
            return $this->error('No tiene permisos para realizar esta acción.', 403);
        }

        if ($product->orderItems()->exists()) {
            $product->update(['is_active' => false]);

            return $this->error('El producto tiene órdenes asociadas; se desactivó en lugar de eliminarse.', 409);
        }

        $product->delete();

        return $this->success(null, 'Producto eliminado correctamente.');
    }
}
