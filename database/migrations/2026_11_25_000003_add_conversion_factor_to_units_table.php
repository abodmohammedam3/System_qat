<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * إضافة معامل التحويل لجدول الوحدات
     * -----------------------------------------------------
     * معامل التحويل = عدد الجرامات في وحدة واحدة من هذه الوحدة.
     *  - جرام  = 1
     *  - كيلو  = 1000
     *  - حبة   = 1 (افتراضي) — الوزن الفعلي للحبة يُدخل مستخدمًا
     *    في نافذة التوزيع (unit_weight) ويُستخدم بدلاً من هذا المعامل.
     */
    public function up(): void
    {
        if (!Schema::hasTable('units') || Schema::hasColumn('units', 'conversion_factor')) {
            return;
        }

        Schema::table('units', function (Blueprint $table) {
            $table->decimal('conversion_factor', 14, 4)->default(1)
                  ->comment('عدد الجرامات في وحدة واحدة (جرام=1، كيلو=1000)');
        });

        // تعبئة القيم المبدئية حسب اسم الوحدة إن وُجدت
        $kgNames = ['كيلو', 'كيلوجرام', 'كيلو جرام', 'كيلوغرام', 'kg', 'KG', 'Kg'];
        $gNames  = ['جرام', 'غرام', 'g', 'G', 'جم'];

        try {
            DB::table('units')->whereIn('UnitName', $kgNames)->update(['conversion_factor' => 1000]);
            DB::table('units')->whereIn('UnitName', $gNames)->update(['conversion_factor' => 1]);
        } catch (\Throwable $e) {
            // تعبئة اختيارية — لا نكسر التهجير عند فشلها
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('units') && Schema::hasColumn('units', 'conversion_factor')) {
            Schema::table('units', function (Blueprint $table) {
                $table->dropColumn('conversion_factor');
            });
        }
    }
};
