<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsNotBlocked
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle($request, Closure $next)
    {
        $user = Auth::guard('sanctum')->user();

        if ($user && $user->is_blocked) {
            if ($user->blocked_until && now()->lessThan($user->blocked_until)) {
                return response()->json([
                    'message' => 'Аккаунт заблокирован',
                    'blocked_until' => $user->blocked_until->format('Y-m-d H:i:s'),
                ], 403);
            } else {
                // Автоматическая разблокировка
                $user->update([
                    'is_blocked' => false,
                    'blocked_until' => null,
                ]);
            }
        }

        return $next($request);
    }
}
