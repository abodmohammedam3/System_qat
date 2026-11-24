<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * تطوير جدول تفاصيل التوزيع ليطابق نافذة التوزيع الجديدة (8 أعمدة)
     * -----------------------------------------------------
     *  - type_id       : نوع الصنف يختاره المستخدم من شاشة الأنواع.
     *  - unit_id       : وحدة التوزيع (افتراضياً "حبة") من شاشة الوحدات.
     *  - unit_weight   : وزن الوحدة بالجرام (مثال 300 = 300 جرام للحبة،
     *                    1500 = كيلو ونصف). يُترك NULL للجرام/الكيلو.
     *  - quantity_base : الكمية محوّلة إلى وحدة الدفعة الأصلية من فاتورة
     *                    الشراء (يُستخدم لخصم المخزون والتحقق من المتاح
     *                    دون خلط الوحدات).
     *  - total         : إجمالي الصف = quantity × sale_price.
     */
    public function up(): void
    {
        if (!Schema::hasTable('distribution_details')) {
            return;
        }

        Schema::table('distribution_details', function (Blueprint $table) {
            if (!Schema::hasColumn('distribution_details', 'type_id')) {
                $table->unsignedBigInteger('type_id')->nullable()
                      ->comment('النوع من type (يُختار في نافذة التوزيع)');
            }
            if (!Schema::hasColumn('distribution_details', 'unit_id')) {
                $table->unsignedBigInteger('unit_id')->nullable()
                      ->comment('وحدة التوزيع من units (افتراضي: حبة)');
            }
            if (!Schema::hasColumn('distribution_details', 'unit_weight')) {
                $table->decimal('unit_weight', 14, 4)->nullable()
                      ->comment('وزن الوحدة بالجرام (وزن الحبة)');
            }
            if (!Schema::hasColumn('distribution_details', 'quantity_base')) {
                $table->decimal('quantity_base', 18, 6)->default(0)
                      ->comment('الكمية محولة إلى وحدة دفعة الشراء');
            }
            if (!Schema::hasColumn('distribution_details', 'total')) {
                $table->decimal('total', 18, 6)->default(0)
                      ->comment('إجمالي الصف = quantity × sale_price');
            }
        });

        // الفهارس — تضاف في عملية منفصلة وبتحقق مسبق
        $indexes = [];
        try {
            $indexes = array_change_key_case(
                Schema::getConnection()->getDoctrineSchemaManager()->listTableIndexes('distribution_details'),
                CASE_LOWER
            );
        } catch (\Throwable $e) {
            $indexes = [];
        }

        Schema::table('distribution_details', function (Blueprint $table) use ($indexes) {
            if (Schema::hasColumn('distribution_details', 'type_id')
                && !isset($indexes['distribution_details_type_id_index'])) {
                $table->index('type_id');
            }
            if (Schema::hasColumn('distribution_details', 'unit_id')
                && !isset($indexes['distribution_details_unit_id_index'])) {
                $table->index('unit_id');
            }
        });

        // تعبئة quantity_base للصفوف القديمة (الصفوف القديمة كانت بوحدة الدفعة نفسها)
        try {
            if (Schema::hasColumn('distribution_details', 'quantity_base')) {
                \Illuminate\Support\Facades\DB::table('distribution_details')
                    ->where('quantity_base', 0)
                    ->update(['quantity_base' => \Illuminate\Support\Facades\DB::raw('quantity')]);
            }
        } catch (\Throwable $e) {
            // تعبئة اختيارية — لا نكسر التهجير عند فشلها
        }

        // المفاتيح الأجنبية (إضافة آمنة مع تجاهل الفشل عند التكرار)
        try {
            Schema::table('distribution_details', function (Blueprint $table) {
                if (Schema::hasColumn('distribution_details', 'type_id')) {
                    $table->foreign('type_id')
                          ->references('id')->on('type')
                          ->onDelete('restrict')
                          ->onUpdate('cascade');
                }
            });
        } catch (\Throwable $e) {
            // موجود مسبقاً — تجاهل
        }

        try {
            Schema::table('distribution_details', function (Blueprint $table) {
                if (Schema::hasColumn('distribution_details', 'unit_id')) {
                    $table->foreign('unit_id')
                          ->references('UnitID')->on('units')
                          ->onDelete('restrict')
                          ->onUpdate('cascade');
                }
            });
        } catch (\Throwable $e) {
            // موجود مسبقاً — تجاهل
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('distribution_details')) {
            return;
        }

        Schema::table('distribution_details', function (Blueprint $table) {
            foreach (['type_id', 'unit_id'] as $col) {
                if (Schema::hasColumn('distribution_details', $col)) {
                    try {
                        $table->dropForeign([$col]);
                    } catch (\Throwable $e) {
                        // تجاهل
                    }
                }
            }
        });

        Schema::table('distribution_details', function (Blueprint $table) {
            foreach (['type_id', 'unit_id', 'unit_weight', 'quantity_base', 'total'] as $col) {
                if (Schema::hasColumn('distribution_details', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
