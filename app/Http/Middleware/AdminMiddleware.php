<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    /**
     * Periksa apakah pengguna yang terautentikasi memiliki peran admin.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->isAdmin()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Akses ditolak. Hanya admin yang diizinkan.',
            ], 403);
        }

        return $next($request);
    }
}
