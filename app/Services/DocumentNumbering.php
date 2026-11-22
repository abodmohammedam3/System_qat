<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * مُصدِر أرقام المستندات الذرّي (بديل نمط MAX+1 — الفحص F-17).
 *
 * كيف يعمل:
 *  1) عند أول استخدام لكل مفتاح يُهيأ العداد من أعلى رقم قائم في الجدول
 *     (لضمان التوافق مع البيانات الحالية دون تكرار أرقام).
 *  2) ثم يُرفع العداد داخل معاملة مع lockForUpdate على صف العداد نفسه —
 *     أمان مطلق على مستوى قاعدة البيانات حتى لو تعددت الخوادم أو تعطل
 *     مخزن الكاش (النمط القديم كان يعتمد على Cache::lock وحده).
 *  3) العداد لا يتناقص أبداً: حذف آخر فاتورة لا يُعيد استخدام رقمها.
 *
 * الاستخدام:
 *   DocumentNumbering::allocate('sales_invoices');          // 1, 2, 3 ...
 */
class DocumentNumbering
{
    /**
     * صدّر الرقم التالي للمفتاح المطلوب (ذرّي وغير قابل للتراجع).
     */
    public static function allocate(string $docKey): int
    {
        $docKey = trim($docKey);

        if ($docKey === '') {
            throw new RuntimeException('مفتاح عداد المستندات فارغ');
        }

        return DB::transaction(function () use ($docKey) {
            // إنشاء صف العداد عند أول استخدام مع تهيئته من أعلى رقم قائم
            $row = DB::table('document_counters')
                ->where('doc_key', $docKey)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                $seed = self::seedFromTable($docKey);

                // أول إصدار: العداد يخزّن الرقم المُصدَر نفسه (لا الرقم السابق
                // له) — وإلا تعاد نفس العدد في الإصدار التالي.
                DB::table('document_counters')->insert([
                    'doc_key'     => $docKey,
                    'last_number' => $seed + 1,
                    'updated_at'  => now(),
                ]);

                return $seed + 1;
            }

            $next = (int) $row->last_number + 1;

            DB::table('document_counters')
                ->where('doc_key', $docKey)
                ->update([
                    'last_number' => $next,
                    'updated_at'  => now(),
                ]);

            return $next;
        });
    }

    /**
     * تهيئة العداد من أعلى رقم موجود في جدول المستند المرتبط بالمفتاح.
     * تُرجع 0 إذا كان الجدول فارغاً أو المفتاح غير مربوط بجدول.
     */
    protected static function seedFromTable(string $docKey): int
    {
        $map = [
            'sales_invoices'        => ['sales_invoices', 'invoice_number'],
            'purchase_invoices'     => ['purchase_invoices', 'invoice_number'],
            'sales_returns'         => ['sales_returns', 'return_number'],
            'purchase_returns'      => ['purchase_returns', 'return_number'],
        ];

        if (! isset($map[$docKey])) {
            return 0;
        }

        [$table, $column] = $map[$docKey];

        if (! DB::getSchemaBuilder()->hasTable($table)) {
            return 0;
        }

        $max = DB::table($table)
            ->selectRaw("CAST(MAX(CAST({$column} AS UNSIGNED)) AS UNSIGNED) as max_num")
            ->value('max_num');

        return (int) ($max ?? 0);
    }
}
