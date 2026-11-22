<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * حرّر قفل الجلسة فورًا للطلبات AJAX/JSON **القرائية فقط**
 * لمنع تعطّل الطلبات المتوازية بسبب Session Lock.
 *
 * تعديل الفحص (F-09): كان يحرر القفل لكل طلب AJAX بما فيها POST/PUT/DELETE —
 * يحل مشكلة تعاقب الطلبات في الاستخدام الفردي لكنه يفتح سباقات كتابة
 * للجلسة عند تعدد المستخدمين (طلبان POST متوازيان يكتبان payload مختلفاً
 * وآخرهما يكسب). الآن: GET/HEAD/OPTIONS فقط تُحرر القفل مبكراً،
 * وطلبات الكتابة تُكمل بعزل الجلسة الطبيعي.
 */
class ReleaseSessionLock
{
    public function handle(Request $request, Closure $next): Response
    {
        if (
            $request->isMethodSafe()
            && ($request->expectsJson() || $request->ajax())
        ) {
            session()->save();
        }

        return $next($request);
    }
}
