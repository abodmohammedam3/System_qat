<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * أعمدة المصادقة والصلاحيات على جدول المستخدمين (الفحص F-01).
 *
 * النظام كان بلا أي نموذج مستخدم رغم وجود جدول users الفارغ. هذا الملف
 * يضيف: الدور (admin/accountant/viewer)، حالة التفعيل، وآخر دخول.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'role')) {
                $table->string('role', 20)->default('accountant')
                    ->after('password')
                    ->comment('الدور: admin / accountant / viewer');
            }

            if (! Schema::hasColumn('users', 'is_active')) {
                $table->boolean('is_active')->default(true)
                    ->after('role')
                    ->comment('تعطيل الحساب يمنع تسجيل الدخول');
            }

            if (! Schema::hasColumn('users', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable()
                    ->after('is_active')
                    ->comment('آخر تسجيل دخول ناجح');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['last_login_at', 'is_active', 'role'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
