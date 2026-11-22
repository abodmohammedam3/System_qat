<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;

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
            return Auth::check(); // حتى مع التعطيل: الدخول إلزامي
        }

        return Auth::check() && Auth::user()->can($permission);
    }

    /**
     * تحقق + رمي 403 عند المنع.
     */
    public static function authorize(string $permission): void
    {
        if (! static::can($permission)) {
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
