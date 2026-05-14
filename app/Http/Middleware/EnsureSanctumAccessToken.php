<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSanctumAccessToken
{
    /**
     * Block refresh-only tokens from accessing the API (legacy ['*'] still allowed).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if (!$token) {
            return $next($request);
        }

        $abilities = $token->abilities ?? [];
        if (in_array('*', $abilities, true) || in_array('access', $abilities, true)) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'Invalid token type',
        ], 403);
    }
}
