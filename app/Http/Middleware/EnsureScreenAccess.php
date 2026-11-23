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

        if (! $user || $user->isAdmin()) {
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

        if ($screen['locked'] || ! $screen['hidden']) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'success' => false,
                'message' => "تم إخفاء شاشة «{$screen['name']}» عن دورك — راجع مدير النظام",
            ], 403);
        }

        abort(403, "تم إخفاء شاشة «{$screen['name']}» عن دورك — راجع مدير النظام");
    }

    /** إيجاد الشاشة المطابقة للمسار مع حالة الدور عليها (كاش 60 ثانية). */
    private function matchScreen(string $path, User $user): ?array
    {
        $screens = Cache::remember('screens.registry', 60, fn () => Screen::query()
            ->orderBy('sort')->get(['id', 'key', 'name', 'locked'])
            ->map(fn (Screen $s) => [
                'id' => $s->id, 'key' => $s->key,
                'name' => $s->name, 'locked' => (bool) $s->locked,
            ])->all());

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

        $reg = collect($screens)->firstWhere('key', $matched['key']);
        if (! $reg) {
            return null;
        }

        $pivot = $user->roleRecord
            ?->screens()
            ->whereKey($reg['id'])
            ->first()?->pivot;

        return [
            'key'    => $reg['key'],
            'name'   => $reg['name'],
            'locked' => $reg['locked'],
            // غياب الصف = غير مخفية (توافق خلفي)
            'hidden' => (bool) ($pivot->hidden ?? false),
        ];
    }

    /** مطابقة نمط بسيط: 'customers*' أو 'reports*'. */
    private function patternMatches(string $pattern, string $path): bool
    {
        $pattern = rtrim($pattern, '*');

        return $pattern === '' ? false : str_starts_with($path, $pattern);
    }
}
