<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * حذف عمود النوع (type_id) من تفاصيل فاتورة الشراء فقط
     * -----------------------------------------------------
     * - النوع لم يعد يُدخل في فاتورة الشراء، بل يُختار لاحقاً
     *   في شاشة التوزيع (distribution_details.type_id).
     * - عمود type_id في inventory_movement_details يبقى موجوداً
     *   لأن التوزيع سيملؤه عند إصدار حركة "توزيع".
     */
    public function up(): void
    {
        if (!Schema::hasTable('purchase_invoice_details')) {
            return;
        }

        Schema::table('purchase_invoice_details', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_invoice_details', 'type_id')) {
                try {
                    $table->dropForeign(['type_id']);
                } catch (\Throwable $e) {
                    // المفتاح الأجنبي غير موجود — نتجاهل
                }
                try {
                    $table->dropIndex('purchase_invoice_details_type_id_index');
                } catch (\Throwable $e) {
                    // الفهرس غير موجود — نتجاهل
                }
            }
        });

        Schema::table('purchase_invoice_details', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_invoice_details', 'type_id')) {
                $table->dropColumn('type_id');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('purchase_invoice_details')) {
            return;
        }

        Schema::table('purchase_invoice_details', function (Blueprint $table) {
            if (!Schema::hasColumn('purchase_invoice_details', 'type_id')) {
                $table->unsignedBigInteger('type_id')->nullable()
                      ->comment('النوع من type (أُعيد في التراجع فقط)');
                $table->index('type_id');
                $table->foreign('type_id')
                      ->references('id')->on('type')
                      ->onDelete('restrict')
                      ->onUpdate('cascade');
            }
        });
    }
};
