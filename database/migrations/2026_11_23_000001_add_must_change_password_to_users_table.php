<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * عمود إجبار تغيير كلمة المرور عند أول دخول (الإصلاح الأمني).
 *
 * سابقاً: DatabaseSeeder كان يزرع كلمة مرور افتراضية معروفة (Admin@12345)
 * مكتوبة في الكود بلا أي إجبار على تغييرها — الآن العلم يُرفع عند الزرع
 * ويُخفَّض تلقائياً بعد أول تغيير ناجح من شاشة "تغيير كلمة المرور".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'must_change_password')) {
                $table->boolean('must_change_password')->default(false)
                    ->after('is_active')
                    ->comment('إجبار تغيير كلمة المرور عند أول دخول');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'must_change_password')) {
                $table->dropColumn('must_change_password');
            }
        });
    }
};
