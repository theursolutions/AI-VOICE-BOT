<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InternalWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.python.internal_secret');
        $provided = $request->header('X-Internal-Secret');

        if (!$expected || !$provided || !hash_equals($expected, $provided)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
