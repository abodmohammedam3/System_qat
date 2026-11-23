<?php

namespace App\Http\Middleware;

use App\Models\Screen;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * المنع الخلفي لشاشات النظام (ديناميكي).
 *
 * إذا كان الدور يملك hidden=true على الشاشة المطابقة للمسار الحالي:
 *   - HTML → 403 مع رسالة عربية.
 *   - JSON/AJAX → 403 JSON (تعالجها الواجهة كـ toast).
 *
 * الاستثناءات: المدير يرى كل شيء، والشاشات المقفلة (locked) لا تُمنع أبداً،
 * والشاشة بلا صف في role_screen = متاحة افتراضياً (توافق خلفي).
 */
class EnsureScreenAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $path = trim($request->path());
        if ($path === '' || $path === '/') {
            $path = 'dashboard';
        }

        $screen = $this->matchScreen($path, $user);

        if ($screen === null) {
            return $next($request); // لا شاشة مطابقة — ليس من نطاق هذا الميدلوير
        }

        // الشاشات الأساسية المقفلة (اللوحة والإعدادات) لا تُمنع أبداً
        if ($screen['locked']) {
            return $next($request);
        }

        // 1) فحص إخفاء الشاشة: ينطبق على أي دور تم تفعيل إخفاء الشاشة له
        if ($screen['hidden']) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => "تم إخفاء شاشة «{$screen['name']}» عن دورك — راجع مدير النظام",
                ], 403);
            }

            abort(403, "تم إخفاء شاشة «{$screen['name']}» عن دورك — راجع مدير النظام");
        }

        // 2) مدير النظام يملك كافة الصلاحيات التشغيلية (عرض، إضافة، تعديل، حذف، طباعة) دائماً
        if ($user->isAdmin()) {
            return $next($request);
        }

        // 3) فحص صلاحيات العمليات للأدوار الأخرى عند وجود تخصيص في المصفوفة
        if ($screen['has_pivot']) {
            $action = $this->determineAction($request, $path);

            if ($action && ! ($screen[$action] ?? true)) {
                $actionLabels = [
                    'view'   => 'عرض',
                    'create' => 'إضافة',
                    'update' => 'تعديل',
                    'delete' => 'حذف',
                    'print'  => 'طباعة',
                ];
                $label = $actionLabels[$action] ?? $action;
                $msg = "ليس لديك صلاحية {$label} في شاشة «{$screen['name']}» — راجع مدير النظام";

                if ($request->expectsJson() || $request->ajax()) {
                    return response()->json([
                        'success' => false,
                        'message' => $msg,
                    ], 403);
                }

                abort(403, $msg);
            }
        }

        return $next($request);
    }

    /** تحديد نوع العملية المطلوبة (view, create, update, delete, print). */
    private function determineAction(Request $request, string $path): ?string
    {
        if (str_contains($path, 'print')) {
            return 'print';
        }

        $method = strtoupper($request->method());

        if ($method === 'GET' || $method === 'HEAD') {
            return 'view';
        }

        if ($method === 'DELETE') {
            return 'delete';
        }

        if ($method === 'PUT' || $method === 'PATCH') {
            return 'update';
        }

        if ($method === 'POST') {
            // العمليات التابعة للتعديل والترحيل المرسلة عبر POST
            if (
                str_contains($path, 'toggle')
                || str_contains($path, 'post')
                || str_contains($path, 'unpost')
                || str_contains($path, 'restore')
            ) {
                return 'update';
            }

            return 'create';
        }

        return null;
    }

    /** إيجاد الشاشة المطابقة للمسار مع حالة الدور عليها. */
    private function matchScreen(string $path, User $user): ?array
    {
        static $screensRegistry = null;
        if ($screensRegistry === null) {
            $screensRegistry = Screen::query()
                ->orderBy('sort')
                ->get(['id', 'key', 'name', 'locked'])
                ->keyBy('key');
        }

        $matched = null;
        foreach (config('screens', []) as $def) {
            foreach ($def['patterns'] as $pattern) {
                if ($this->patternMatches($pattern, $path)) {
                    $matched = $def;
                    break 2;
                }
            }
        }

        if ($matched === null) {
            return null;
        }

        $reg = $screensRegistry->get($matched['key']);
        if (! $reg) {
            return null;
        }

        $pivot = $user->roleRecord
            ?->screens()
            ->where('screens.id', $reg->id)
            ->first()?->pivot;

        return [
            'id'        => $reg->id,
            'key'       => $reg->key,
            'name'      => $reg->name,
            'locked'    => (bool) $reg->locked,
            // غياب الصف = غير مخفية (توافق خلفي)
            'hidden'    => (bool) ($pivot->hidden ?? false),
            'view'      => (bool) ($pivot->view ?? true),
            'create'    => (bool) ($pivot->create ?? false),
            'update'    => (bool) ($pivot->update ?? false),
            'delete'    => (bool) ($pivot->delete ?? false),
            'print'     => (bool) ($pivot->print ?? false),
            'has_pivot' => $pivot !== null,
        ];
    }

    /** مطابقة نمط بسيط: 'customers*' أو 'reports*'. */
    private function patternMatches(string $pattern, string $path): bool
    {
        $pattern = rtrim($pattern, '*');

        return $pattern === '' ? false : str_starts_with($path, $pattern);
    }
}
