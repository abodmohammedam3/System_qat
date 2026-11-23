<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * المستوى الثاني — بذر الأدوار والصلاحيات الديناميكية.
 *
 * - يزرع الأدوار الثلاثة كأدوار نظامية (is_system) مطابقة للوضع القديم.
 * - يزرع كل صلاحية من خريطة config/permissions.php (مصدر التهجرة).
 * - يربط accountant/viewer بصلاحيات مطابقة للمنطق القديم في
 *   User::rolePermissions() — admin يحصل على الكل عبر Gate::before
 *   وربطه بالكامل هنا أيضاً للتوثيق في قاعدة البيانات.
 * - يربط المستخدمين الحاليين بـ role_id حسب عمود role النصي القديم.
 */
class RolesPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // ─── الأدوار النظامية ───
        $admin      = Role::updateOrCreate(['slug' => 'admin'],      ['name' => 'مدير النظام',  'is_system' => true]);
        $accountant = Role::updateOrCreate(['slug' => 'accountant'], ['name' => 'محاسب',        'is_system' => true]);
        $viewer     = Role::updateOrCreate(['slug' => 'viewer'],     ['name' => 'مشاهد فقط',    'is_system' => true]);

        // ─── الصلاحيات من خريطة config/permissions.php ───
        $all = collect(config('permissions', []))
            ->filter(fn ($v) => is_array($v))
            ->flatMap(fn (array $actions) => array_values($actions))
            ->unique()
            ->values();

        $permissions = $all->map(fn (string $slug) => Permission::updateOrCreate(
            ['slug' => $slug],
            ['module' => str($slug)->before('.')->toString()]
        ));

        // ─── admin: كل الصلاحيات ───
        $admin->permissions()->sync($permissions->pluck('id'));

        // ─── accountant: كل عمليات الموديولات التشغيلية ────────────────
        // (مطابق للمنطق القديم في User::rolePermissions سطر 89-95)
        $accountantSlugs = $permissions
            ->filter(fn (Permission $p) => in_array($p->module, [
                'purchase_invoices', 'purchase_returns', 'sales_invoices',
                'sales_returns', 'payment_vouchers', 'receipt_vouchers',
            ], true))
            ->pluck('slug');
        $accountant->permissions()->sync(
            Permission::whereIn('slug', $accountantSlugs)->pluck('id')
        );

        // ─── viewer: عرض وطباعة فقط (مطابق للمنطق القديم سطر 79-87) ───
        $viewerSlugs = $accountantSlugs
            ->filter(fn (string $slug) => str($slug)->endsWith('.view') || str($slug)->endsWith('.print'));
        $viewer->permissions()->sync(
            Permission::whereIn('slug', $viewerSlugs)->pluck('id')
        );

        // ─── ربط المستخدمين الحاليين بـ role_id (توافق خلفي) ───
        $map = ['admin' => $admin->id, 'accountant' => $accountant->id, 'viewer' => $viewer->id];
        foreach ($map as $legacyRole => $roleId) {
            User::where('role', $legacyRole)->whereNull('role_id')->update(['role_id' => $roleId]);
        }

        // ─── زرع الشاشات من config/screens.php ───
        $this->call(ScreensSeeder::class);
    }
}
