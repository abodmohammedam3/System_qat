<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * حماية وحدة النسخ الاحتياطي (الفحص F-01/F-02).
 *
 * سابقاً: كان هذا الميدلوير مفتوحاً نصياً ("حالياً مفتوح — لا يوجد نظام
 * صلاحيات في المشروع") فكان تصدير/استيراد/حذف قاعدة البيانات متاحاً
 * لأي زائر يعرف الرابط.
 *
 * الآن: يتطلب مستخدماً مسجلاً + صلاحية manage-backup (المدير فقط).
 */
class CanManageBackup
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->can('manage-backup')) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'لا تملك صلاحية إدارة النسخ الاحتياطي',
                ], 403);
            }

            abort(403, 'لا تملك صلاحية إدارة النسخ الاحتياطي');
        }

        return $next($request);
    }
}
