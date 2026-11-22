<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // ═══════════════════════════════════════════════════════
        // المصادقة الإلزامية على كل مسارات web (الفحص F-01)
        // يضاف في المقدمة ليعمل قبل بقية الميدلويرات، ويستثني مسار
        // /login فقط (قائمة الاستثناءات داخل EnsureAuthenticated).
        // ═══════════════════════════════════════════════════════
        $middleware->web(prepend: [
            \App\Http\Middleware\EnsureAuthenticated::class,
        ]);

        // تحرير قفل الجلسة مبكراً لطلبات AJAX/JSON القرائية (GET فقط بعد
        // تعديل الفحص F-09) — يمنع تزاحم الطلبات المتوازية دون فتح سباقات كتابة.
        $middleware->web(append: [
            \App\Http\Middleware\ReleaseSessionLock::class,
        ]);

        // ملاحظة أداء: EnsureWorkerAlive كان يعمل على كل طلب ويلمس الكاش/قاعدة البيانات.
        // أُخرج من مجموعة web مؤقتاً (لا يوجد Worker/طابور backups مفعّل حالياً).
        // لإعادة تفعيله: أضف \App\Http\Middleware\EnsureWorkerAlive::class مرة أخرى هنا.

        $middleware->alias([
            'can-manage-backup' => \App\Http\Middleware\CanManageBackup::class,
            'admin'             => \App\Http\Middleware\EnsureAdmin::class,
        ]);

        // السماح بمتابعة عمليات النسخ الاحتياطي وتنزيل النتائج
        // حتى أثناء وضع الصيانة (الذي يُفعَّل أثناء الاستيراد في الإنتاج)
        $middleware->preventRequestsDuringMaintenance(except: [
            'settings/system/backup/operations/*',
            'settings/system/backup/download/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();