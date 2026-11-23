<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * إلزام المصادقة على كل مسارات النظام (الفحص F-01).
 *
 * يُسجَّل في مقدمة مجموعة web في bootstrap/app.php فيحمي كل مسارات
 * routes/web.php الـ224 دفعة واحدة دون تعديل ملف المسارات.
 *
 * الاستثناءات (المسارات المسموحة للزائر فقط):
 *   - GET/POST /login   صفحة وتنفيذ الدخول
 *
 * سلوك الرفض:
 *   - طلبات JSON/AJAX → 401 JSON (حتى تعرض الواجهة رسالة مناسبة بدل HTML)
 *   - بقية الطلبات    → تحويل إلى صفحة الدخول
 */
class EnsureAuthenticated
{
    /** المسارات المتاحة للزائر (بدون تسجيل دخول) */
    protected array $guestPaths = [
        'login',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $path = trim($request->path());

        foreach ($this->guestPaths as $guestPath) {
            if ($path === $guestPath || str_starts_with($path, $guestPath . '/')) {
                // زائر على مسار عام — لكن إن كان مسجلاً فعلاً حوّله للوحة
                if (Auth::check() && $request->isMethod('GET')) {
                    return redirect()->route('dashboard');
                }

                return $next($request);
            }
        }

        if (! Auth::check()) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'انتهت الجلسة أو لم تسجل الدخول — أعد تحميل الصفحة لتسجيل الدخول',
                ], 401);
            }

            return redirect()->guest(route('login'));
        }

        // منع دخول الحسابات المعطلة حتى لو كانت الجلسة قائمة
        $user = Auth::user();

        if (!$user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'تم تعطيل حسابك — راجع مدير النظام',
                ], 403);
            }

            return redirect()->route('login')->with('error', 'تم تعطيل حسابك — راجع مدير النظام');
        }

        // إجبار تغيير كلمة المرور عند أول دخول (الإصلاح الأمني):
        // كل المسارات محجوبة عدا صفحة/تنفيذ تغيير كلمة المرور وتسجيل الخروج.
        if (
            $user->must_change_password
            && !$request->routeIs('profile.password.*')
            && !$request->routeIs('logout')
        ) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'يجب تغيير كلمة المرور أولاً',
                    'must_change_password' => true,
                ], 403);
            }

            return redirect()->route('profile.password.show')
                ->with('warning', 'يجب تغيير كلمة المرور المؤقتة قبل المتابعة');
        }

        return $next($request);
    }
}
