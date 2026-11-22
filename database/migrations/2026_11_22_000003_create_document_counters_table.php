<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جدول عدّادات المستندات (الفحص F-17).
 *
 * ترقيم الفواتير كان بنمط MAX+1 مع قفل تطبيقي Cache::lock — محصّن على خادم
 * واحد لكنه ينهار إذا تعطل مخزن الكاش أو تعددت الخوادم، والحذف النهائي
 * لآخر فاتورة يجعل رقمها قابلاً لإعادة الاستخدام (مرفوض محاسبياً).
 * هذا الجدول يوفر عدّاداً ذرّياً بـ lockForUpdate لا يعود للخلف أبداً.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('document_counters')) {
            return;
        }

        Schema::create('document_counters', function (Blueprint $table) {
            $table->string('doc_key', 50)->primary()
                ->comment('مفتاح العداد: sales_invoices / purchase_invoices / ...');
            $table->unsignedBigInteger('last_number')->default(0)
                ->comment('آخر رقم مُصدر (لا يتناقص أبداً)');
            $table->timestamp('updated_at')->nullable()
                ->comment('آخر تحديث');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_counters');
    }
};
