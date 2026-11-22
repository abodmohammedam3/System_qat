<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * توحيد أسماء الجداول الموجودة في قواعد بيانات قديمة (منشأة على Windows)
 * مع الأسماء التي يستخدمها الكود فعلياً (snake_case صغيرة).
 *
 * السياق المثبت من الفحص:
 *  - هجرات الإنشاء كانت تعمل MySQL بـ lower_case_table_names=1 (Windows)
 *    فأنشأت جداول بأسماء مختلطة الأحرف: Items / Customers / Suppliers /
 *    openingBalances — بينما نماذج Eloquent والتقارير تستعلم بأسماء صغيرة
 *    (items / customers / suppliers / opening_balances).
 *  - على استضافة Linux (lower_case_table_names=0) هذا يعني فشل كل استعلام
 *    على هذه الجداول (Table not found).
 *
 * الحماية: يعيد التسمية فقط إذا كان الاسم القديم موجوداً والجديد غير موجود
 * (idempotent) — لذلك لا يفعل شيئاً على التثبيت الجديد حيث الهجرات
 * المُصلحة تنشئ الأسماء الصغيرة مباشرة.
 *
 * التوقيع 2026_09_16_225500 مقصود: قبل أول هجرة لاحقة تشير إلى
 * 'opening_balances' (2026_09_16_225557).
 */
return new class extends Migration
{
    /**
     * الاسم القديم (كما أنشأته الهجرات القديمة) => الاسم المعتمد في الكود.
     */
    private const RENAMES = [
        'Items'           => 'items',
        'Customers'       => 'customers',
        'Suppliers'       => 'suppliers',
        'openingBalances' => 'opening_balances',
    ];

    public function up(): void
    {
        // تعطيل فحص المفاتيح الأجنبية مؤقتاً حتى تُحدَّث المراجع تلقائياً
        // بواسطة RENAME TABLE دون أي حاجز (InnoDB يحدّث تعريفات FK تلقائياً).
        Schema::getConnection()->statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach (self::RENAMES as $old => $new) {
                if (Schema::hasTable($old) && ! Schema::hasTable($new)) {
                    Schema::rename($old, $new);
                }
            }
        } finally {
            Schema::getConnection()->statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    public function down(): void
    {
        Schema::getConnection()->statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach (self::RENAMES as $old => $new) {
                if (Schema::hasTable($new) && ! Schema::hasTable($old)) {
                    Schema::rename($new, $old);
                }
            }
        } finally {
            Schema::getConnection()->statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }
};
