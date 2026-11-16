<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Las rutas /api/* sin versión siguen funcionando por compatibilidad, pero avisan
 * (encabezados Deprecation y Link, RFC 9745 / RFC 8288) que la versión actual es /api/v1.
 */
class DeprecatedApiVersion
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $successor = '/api/v1/'.ltrim(substr($request->path(), strlen('api')), '/');
        $response->headers->set('Deprecation', 'true');
        $response->headers->set('Link', '<'.url($successor).'>; rel="successor-version"');

        return $response;
    }
}
