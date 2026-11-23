<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;

use App\Models\User;
use App\Models\Accounting\CharAccount;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\Bank;
use App\Models\Accounting\Box;
use App\Models\Inventory\Stock;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\Purchases\PurchaseInvoice;
use App\Models\Purchases\PurchaseReturn;
use App\Models\Sales\SalesInvoice;
use App\Models\Sales\SalesReturn;
use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\InventoryMovementDetail;
use App\Models\Accounting\Coin;


use App\Observers\CharAccountObserver;
use App\Observers\BankObserver;
use App\Observers\BoxObserver;
use App\Observers\StockObserver;
use App\Observers\CustomerObserver;
use App\Observers\SupplierObserver;
use App\Observers\PurchaseInvoiceObserver;
use App\Observers\SalesInvoiceObserver;
use App\Observers\CoinObserver;
use App\Listeners\RecordSystemActivity;
use App\Listeners\FlushDashboardCache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // مشاركة الشاشات المخفية عن دور المستخدم مع كل العروض —
        // يستخدمها السايدبار لإخفاء الروابط (المنع الخلفي في الميدلوير).
        View::composer('layouts.*', function ($view) {
            $user = auth()->user();
            $hidden = [];

            if ($user && ! $user->isAdmin() && $user->role_id) {
                $hidden = $user->roleRecord->screens()
                    ->wherePivot('hidden', true)
                    ->pluck('screens.key')
                    ->all();
            }

            $view->with('hiddenScreenKeys', $hidden);
        });
        // ═══════════════════════════════════════════════════════
        // المصادقة والصلاحيات (الفحص F-01)
        // -----------------------------------------------------------------
        // Gates للأدوار الثلاثة: admin (كل شيء)، accountant (العمليات
        // التشغيلية)، viewer (عرض/طباعة فقط). المفاتيح تأتي من
        // config/permissions.php + مفاتيح إدارية (users/backup/settings).
        // ═══════════════════════════════════════════════════════

        Gate::before(function (User $user, string $ability) {
            // المدير يملك كل الصلاحيات دائماً
            if ($user->isAdmin()) {
                return true;
            }

            return null; // تابع الفحص عبر بقية الـ Gates
        });

        // صلاحيات إدارية — للمدير فقط (يتحقق قبل Gate::before)
        Gate::define('manage-users', fn (User $user) => $user->isAdmin());
        Gate::define('manage-backup', fn (User $user) => $user->isAdmin());
        Gate::define('manage-settings', fn (User $user) => $user->isAdmin() || ($user->roleRecord?->slug ?? $user->role) === User::ROLE_ACCOUNTANT);

        // صلاحيات الموديولات من خريطة config/permissions.php
        $modulePermissions = collect(config('permissions', []))
            ->filter(fn ($value) => is_array($value))
            ->flatMap(fn (array $actions) => array_values($actions))
            ->unique()
            ->values();

        foreach ($modulePermissions as $permission) {
            Gate::define($permission, function (User $user) use ($permission) {
                if (! config('permissions.enabled', true)) {
                    return true; // التعطيل اليدوي للتشخيص فقط
                }

                // المستوى الثاني: إن كان للدور حالة على شاشة الموديول، فهي
                // المصدر (وصولاً ديناميكياً من المصفوفة) — وإلا fallback للخريطة.
                [$module, $action] = array_pad(explode('.', $permission, 2), 2, null);

                // الترحيل/إلغاؤه صلاحية موروثة من "تعديل" (طلب المستخدم:
                // لا تظهر في المصفوفة لكنها تعمل في الخلفية).
                if (in_array($action, ['post', 'unpost'], true)) {
                    $action = 'update';
                }

                if ($user->role_id && $user->roleRecord) {
                    $pivot = $user->roleRecord->screens()
                        ->where('screens.key', $module)
                        ->first()?->pivot;

                    if ($pivot) {
                        $field = $action === 'view' ? 'view' : $action;

                        if (in_array($field, ['view', 'create', 'update', 'delete', 'print'], true)) {
                            return (bool) $pivot->{$field};
                        }
                    }
                }

                return in_array('*', $user->rolePermissions(), true)
                    || in_array($permission, $user->rolePermissions(), true);
            });
        }

        // ═══════════════════════════════════════════════════════
        // إجبار HTTPS في الإنتاج (الفحص F-16)
        // -----------------------------------------------------------------
        // APP_FORCE_HTTPS=true في الإنتاج يحول كل الروابط المولّدة
        // (والكوكيز الآمنة) إلى https. الافتراضي معطل لبيئة التطوير المحلية.
        // ═══════════════════════════════════════════════════════
        if (env('APP_FORCE_HTTPS', false)) {
            URL::forceScheme('https');
        }

        CharAccount::observe(CharAccountObserver::class);
        Box::observe(BoxObserver::class);
        Bank::observe(BankObserver::class);
        Stock::observe(StockObserver::class);
        Customer::observe(CustomerObserver::class);
        Supplier::observe(SupplierObserver::class);

        // ✅ جديد: مراقب فاتورة الشراء
        PurchaseInvoice::observe(PurchaseInvoiceObserver::class);
        SalesInvoice::observe(SalesInvoiceObserver::class);
        Coin::observe(CoinObserver::class);

        // مؤشر النشاط منذ آخر نسخة احتياطية (شاشة الإعدادات → النسخ الاحتياطي)
        // يقرأها BackupController::index() ويعرضها بطاقة "مؤشر النشاط"
        foreach ([
            'eloquent.created: '.SalesInvoice::class,
            'eloquent.updated: '.SalesInvoice::class,
            'eloquent.deleted: '.SalesInvoice::class,
            'eloquent.created: '.PurchaseInvoice::class,
            'eloquent.updated: '.PurchaseInvoice::class,
            'eloquent.deleted: '.PurchaseInvoice::class,
            'eloquent.created: '.JournalEntry::class,
            'eloquent.updated: '.JournalEntry::class,
            'eloquent.deleted: '.JournalEntry::class,
        ] as $activityEvent) {
            Event::listen($activityEvent, RecordSystemActivity::class);
        }

        // لوحة المعلومات: إبطال الكاش عند أي كتابة (created/updated/deleted).
        //
        // لماذا هذه النماذج بالذات:
        //  - SalesInvoice / PurchaseInvoice / المرتجعان / InventoryMovement
        //    هي مصادر الأرقام المعروضة (عدّ + إجماليات + حركات).
        //  - InventoryMovementDetail ضروريّ رغم أنه ليس له Observer:
        //    InventoryMovementController::updatePricing() يعدّل sale_price
        //    على التفصيل مباشرة بلا لمس الرأس (سطر 725-728).
        //  - JournalEntry: القيود تنشأ/تُحذف من مسارات لم تمر بالفواتير.
        //  - Customer / Supplier: بطاقتا العدّاد (is_active يغيّر الرقم).
        //
        // لاحظ أن الحذف الجماعي مثل $movement->details()->delete()
        // (SalesInvoiceObserver:39) لا يُطلق أحداث Eloquent — لكنه يُنفَّذ
        // دائمًا داخل حذف الرأس/الفاتورة الذي يُطلق حدثه الخاص.
        foreach ([
            SalesInvoice::class,
            PurchaseInvoice::class,
            SalesReturn::class,
            PurchaseReturn::class,
            InventoryMovement::class,
            InventoryMovementDetail::class,
            JournalEntry::class,
            Customer::class,
            Supplier::class,
        ] as $dashboardModel) {
            foreach (['created', 'updated', 'deleted'] as $dashboardAction) {
                Event::listen(
                    "eloquent.{$dashboardAction}: {$dashboardModel}",
                    FlushDashboardCache::class
                );
            }
        }

        // ملاحظة: كان هنا تحميل مجلد database/bac كمسار هجرات في بيئة الاختبار فقط.
        // أُزيل نهائياً: المجلد يحتوي نسخاً قديمة ومكسورة من الهجرات (أسماء جداول
        // خاطئة وهجرة alter تعتمد على جدول لم يُنشأ بعد) كانت تُفسد migrate:fresh
        // في الاختبارات. الهجرات الرسمية في database/migrations مكتملة الآن وتُنشئ
        // المخطط الصحيح من الصفر (تم التحقق بـ migrate:fresh كاملة).
    }
}