<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * بذر قاعدة البيانات: إنشاء حساب مدير النظام (الفحص F-01).
     *
     * الإصلاح الأمني: أُزيلت كلمة المرور الافتراضية المعروفة (Admin@12345).
     * إن لم تُحدد ADMIN_PASSWORD تُولّد كلمة عشوائية قوية وتُطبع في الطرفية
     * مرة واحدة، مع رفع علم must_change_password لإجبار تغييرها عند أول دخول.
     */
    public function run(): void
    {
        $adminEmail = env('ADMIN_EMAIL', 'admin@erp.local');

        // كلمة عشوائية قوية عند غياب ADMIN_PASSWORD — لا كلمات معروفة في الكود
        $generated   = ! env('ADMIN_PASSWORD');
        $adminPassword = $generated
            ? \Illuminate\Support\Str::password(16, symbols: true)
            : env('ADMIN_PASSWORD');

        User::updateOrCreate(
            ['email' => $adminEmail],
            [
                'name'      => 'مدير النظام',
                'password'  => Hash::make($adminPassword),
                'role'      => User::ROLE_ADMIN,
                'is_active' => true,
                // إجبار تغييرها من شاشة "تغيير كلمة المرور" عند أول دخول
                'must_change_password' => $generated,
            ]
        );

        if ($generated) {
            $this->command?->warn('════════════════════');
            $this->command?->warn('كلمة مرور مدير النظام المولّدة (انسخها الآن — لن تُعرض مرة أخرى):');
            $this->command?->info("  {$adminPassword}");
            $this->command?->warn('سيُطلب منك تغييرها إجبارياً عند أول دخول.');
            $this->command?->warn('════════════════════');
        }

        // دليل الحسابات الكامل (الحسابات النظامية المطلوبة للترحيل)
        $this->call(ChartOfAccountsSeeder::class);

        // الأدوار والصلاحيات الديناميكية (المستوى الثاني)
        $this->call(RolesPermissionsSeeder::class);
    }
}
