<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * حماية المسارات الإدارية (إدارة المستخدمين / النسخ الاحتياطي / التصدير)
 * — للمدير فقط.
 */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->isAdmin()) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'هذه الصفحة متاحة لمدير النظام فقط',
                ], 403);
            }

            abort(403, 'هذه الصفحة متاحة لمدير النظام فقط');
        }

        return $next($request);
    }
}
