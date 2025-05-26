<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string  ...$roles
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        Log::debug('RoleMiddleware: Проверка роли пользователя', [
            'user_id' => Auth::id(),
            'roles' => $roles,
            'request_path' => $request->path(),
        ]);

        $user = Auth::guard('sanctum')->user();

        if (!$user) {
            Log::warning('RoleMiddleware: Пользователь не авторизован');
            return response()->json(['message' => 'Требуется авторизация'], 401);
        }

        // Предполагается, что роль хранится в столбце 'role' модели User
        $userRole = $user->role;

        if (!in_array($userRole, $roles)) {
            Log::warning('RoleMiddleware: Недостаточно прав', [
                'user_id' => $user->id,
                'user_role' => $userRole,
                'required_roles' => $roles,
            ]);
            return response()->json(['message' => 'Недостаточно прав для выполнения действия'], 403);
        }

        Log::info('RoleMiddleware: Доступ разрешен', [
            'user_id' => $user->id,
            'user_role' => $userRole,
        ]);

        return $next($request);
    }
}
