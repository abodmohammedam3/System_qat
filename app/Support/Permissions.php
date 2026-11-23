<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * مساعد الصلاحيات المركزي (مُحدَّث — الفحص F-01).
 *
 * سابقاً: كان يعيد true دائماً عندما تكون الصلاحيات معطلة، والصلاحيات
 * معطلة افتراضياً — أي أن كل شيء مسموح لأي زائر.
 *
 * الآن: النظام بلا مسارات عامة أصلاً (EnsureAuthenticated)، والتحقق
 * يتم عبر Laravel Gates المعرّفة في AppServiceProvider:
 *   - admin    → كل الصلاحيات
 *   - accountant → العمليات التشغيلية (بدون المستخدمين/النسخ الاحتياطي)
 *   - viewer   → العرض والطباعة فقط
 *
 * الاستخدام:
 *   Permissions::can('purchase_invoices.create');
 *   Permissions::authorize('purchase_invoices.delete');
 */
class Permissions
{
    public static function can(string $permission): bool
    {
        // التعطيل اليدوي عبر .env يبقى متاحاً للتشخيص فقط
        if (! config('permissions.enabled', true)) {
            // حماية من الإباحة الصامتة في الإنتاج: تحذير مُسجَّل (مرة كل دقيقة)
            // حتى لا يبقى التعطيل مجهولاً على الخادم.
            static::warnIfDisabled();

            return Auth::check(); // حتى مع التعطيل: الدخول إلزامي
        }

        return Auth::check() && Auth::user()->can($permission);
    }

    /** تحذير مُسجَّل مرة كل دقيقة عند تعطيل نظام الصلاحيات (تجنب إغراق السجل). */
    private static function warnIfDisabled(): void
    {
        $key = 'permissions.disabled.warning';

        if (! cache()->has($key)) {
            Log::warning('نظام الصلاحيات معطّل (PERMISSIONS_ENABLED=false) — كل المستخدمين المسجلين يملكون كل الصلاحيات. لا تستخدمه في الإنتاج.');
            cache()->put($key, true, 60);
        }
    }

    /**
     * تحقق + رمي 403 عند المنع.
     */
    public static function authorize(string $permission): void
    {
        if (! static::can($permission)) {
            // طلبات API/AJAX تستقبل JSON بدلاً من صفحة 403 HTML
            // حتى تتمكن الواجهة من عرض رسالة toast منسّقة.
            if (request()->expectsJson() || request()->ajax()) {
                abort(response()->json([
                    'success' => false,
                    'message' => 'غير مصرّح لك بهذا الإجراء',
                ], 403));
            }

            abort(403, 'غير مصرّح لك بهذا الإجراء');
        }
    }

    /**
     * اختصار للصلاحيات المتكررة.
     */
    public static function any(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (static::can($permission)) {
                return true;
            }
        }

        return false;
    }
}
