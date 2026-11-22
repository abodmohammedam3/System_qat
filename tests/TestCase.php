<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    /**
     * تسجيل دخول تلقائي لمدير النظام أثناء الاختبارات.
     *
     * بعد بناء نظام المصادقة (الفحص F-01) كل مسارات web تتطلب دخولاً —
     * الاختبارات تختبر منطق المتحكمات/الخدمات وليس الدخول نفسه، لذلك
     * يُنشأ مدير ويُسجَّل دخوله تلقائياً لكل اختبار فيه جدول users.
     * اختبارات Unit بلا قاعدة بيانات تتجاهل هذه الخطوة،
     * واختبارات المصادقة نفسها تعطّلها بـ $autoAuthenticate = false.
     */
    protected bool $autoAuthenticate = true;

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->autoAuthenticate && Schema::hasTable('users')) {
            $admin = User::firstOrCreate(
                ['email' => 'admin@test.local'],
                [
                    'name'      => 'Test Admin',
                    'password'  => bcrypt('secret1234'),
                    'role'      => User::ROLE_ADMIN,
                    'is_active' => true,
                ]
            );

            $this->actingAs($admin);
        }
    }
}
