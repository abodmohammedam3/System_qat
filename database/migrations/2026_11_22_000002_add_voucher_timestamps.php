<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * إعادة طوابع الزمن على سندات القبض والدفع (الفحص F-14).
 *
 * كانت timestamps() معطلة بنص الهجرة الأصلية ("تم إلغاء timestamps بناءً على طلبك")
 * فبقي تاريخ السند voucherDate إدخال المستخدم دون أي عمود يوثق متى دخل السند
 * للنظام فعلياً — عنصر تدقيق أساسي في مستند مالي. تُضاف الأعمدة nullable
 * لتفادي كسر السجلات القائمة، وتُعبأ تلقائياً من Eloquent للسندات الجديدة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('receipt_vouchers') && ! Schema::hasColumn('receipt_vouchers', 'created_at')) {
            Schema::table('receipt_vouchers', function (Blueprint $table) {
                $table->timestamps();
            });
        }

        if (Schema::hasTable('payment_vouchers') && ! Schema::hasColumn('payment_vouchers', 'created_at')) {
            Schema::table('payment_vouchers', function (Blueprint $table) {
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('receipt_vouchers') && Schema::hasColumn('receipt_vouchers', 'created_at')) {
            Schema::table('receipt_vouchers', function (Blueprint $table) {
                $table->dropTimestamps();
            });
        }

        if (Schema::hasTable('payment_vouchers') && Schema::hasColumn('payment_vouchers', 'created_at')) {
            Schema::table('payment_vouchers', function (Blueprint $table) {
                $table->dropTimestamps();
            });
        }
    }
};
