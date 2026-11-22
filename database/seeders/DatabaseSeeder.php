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
     * كلمة المرور تأتي من متغير البيئة ADMIN_PASSWORD إن وُجد، وإلا
     * تُستخدم القيمة الافتراضية Admin@12345 — يجب تغييرها فور أول دخول
     * من شاشة "تغيير كلمة المرور" (وإدارة المستخدمين تسمح بذلك).
     */
    public function run(): void
    {
        $adminEmail    = env('ADMIN_EMAIL', 'admin@erp.local');
        $adminPassword = env('ADMIN_PASSWORD', 'Admin@12345');

        User::updateOrCreate(
            ['email' => $adminEmail],
            [
                'name'      => 'مدير النظام',
                'password'  => Hash::make($adminPassword),
                'role'      => User::ROLE_ADMIN,
                'is_active' => true,
            ]
        );

        // دليل الحسابات الكامل (الحسابات النظامية المطلوبة للترحيل)
        $this->call(ChartOfAccountsSeeder::class);
    }
}
