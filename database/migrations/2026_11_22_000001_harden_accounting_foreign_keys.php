<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

/**
 * تحصين المفاتيح الأجنبية للمستندات المحاسبية (الفحص F-06 وF-07).
 *
 * F-06: سندات القبض والدفع كانت مرتبطة بدليل الحسابات بـ cascadeOnDelete —
 *       حذف حساب تحليلي واحد كان يُسقط كل السندات المرتبطة به صامتاً
 *       فتتخرب أرصدة العملاء والموردين وميزان المراجعة. أصبحت
 *       restrictOnDelete (رفض الحذف ما دامت السندات موجودة).
 *
 * F-07: أسطر القيد journal_entry_lines — القلب المحاسبي للنظام — كانت
 *       بلا أي مفتاح أجنبي (فهارس فقط)، ما يسمح نظرياً ببيانات يتيمة
 *       تفقد ميزان المراجعة مصداقيته. أُضيفت المفاتيح الأجنبية.
 *
 * حماية البيانات القائمة: قبل إضافة مفاتيح journal_entry_lines يُفحص
 * وجود أسطر يتيمة (قيد/حساب/عملة محذوفة) — إن وُجدت يفشل الترحيل برسالة
 * واضحة تحدد الأعداد بدلاً من حذف بيانات العميل تلقائياً.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─────────────────────────────────────────────────────
        // 1) سندات القبض: cascade → restrict على FK دليل الحسابات
        //    (الأعمدة الحالية: creditAccountID / debitAccountID)
        // ─────────────────────────────────────────────────────
        if (Schema::hasTable('receipt_vouchers')) {
            Schema::table('receipt_vouchers', function (Blueprint $table) {
                $table->dropForeign('receipt_vouchers_creditaccountid_foreign');
                $table->dropForeign('receipt_vouchers_debitaccountid_foreign');
            });

            Schema::table('receipt_vouchers', function (Blueprint $table) {
                $table->foreign('creditAccountID')
                    ->references('accountID')->on('characcount')
                    ->restrictOnDelete()->cascadeOnUpdate();
                $table->foreign('debitAccountID')
                    ->references('accountID')->on('characcount')
                    ->restrictOnDelete()->cascadeOnUpdate();
            });
        }

        // ─────────────────────────────────────────────────────
        // 2) سندات الدفع: cascade → restrict على FK دليل الحسابات
        //    ⚠️ ملاحظة دقيقة: هجرة 2026_10_01_214035 أعادت تسمية الأعمدة
        //    من creditAccountID/debitAccountID إلى paymentAccountID/
        //    beneficiaryAccountID (أسماء القيود الحالية:
        //    payment_vouchers_payment_account_foreign و
        //    payment_vouchers_beneficiary_account_foreign)
        // ─────────────────────────────────────────────────────
        if (Schema::hasTable('payment_vouchers')) {
            Schema::table('payment_vouchers', function (Blueprint $table) {
                $table->dropForeign('payment_vouchers_payment_account_foreign');
                $table->dropForeign('payment_vouchers_beneficiary_account_foreign');
            });

            Schema::table('payment_vouchers', function (Blueprint $table) {
                $table->foreign('paymentAccountID')
                    ->references('accountID')->on('characcount')
                    ->restrictOnDelete()->cascadeOnUpdate();
                $table->foreign('beneficiaryAccountID')
                    ->references('accountID')->on('characcount')
                    ->restrictOnDelete()->cascadeOnUpdate();
            });
        }

        // ─────────────────────────────────────────────────────
        // 3) أسطر القيد: حماية من البيانات اليتيمة ثم إضافة المفاتيح
        // ─────────────────────────────────────────────────────
        if (Schema::hasTable('journal_entry_lines')) {
            $orphanEntries  = DB::table('journal_entry_lines as l')
                ->leftJoin('journal_entries as e', 'e.entryID', '=', 'l.entryID')
                ->whereNull('e.entryID')->count();
            $orphanAccounts = DB::table('journal_entry_lines as l')
                ->leftJoin('characcount as c', 'c.accountID', '=', 'l.accountID')
                ->whereNull('c.accountID')->count();
            $orphanCoins    = DB::table('journal_entry_lines as l')
                ->leftJoin('coins as k', 'k.coinsID', '=', 'l.coinsID')
                ->whereNotNull('l.coinsID')
                ->whereNull('k.coinsID')->count();

            if ($orphanEntries > 0 || $orphanAccounts > 0 || $orphanCoins > 0) {
                Log::error('harden_fk: orphan journal lines detected', [
                    'orphan_entries' => $orphanEntries,
                    'orphan_accounts' => $orphanAccounts,
                    'orphan_coins' => $orphanCoins,
                ]);

                throw new RuntimeException(
                    "لا يمكن إضافة المفاتيح الأجنبية لأسطر القيد: توجد بيانات يتيمة "
                    . "(أسطر بلا قيد: {$orphanEntries}، أسطر بحساب محذوف: {$orphanAccounts}، "
                    . "أسطر بعملة محذوفة: {$orphanCoins}). "
                    . 'راجع سجل الأخطاء ونظّف السجلات اليتيمة يدوياً ثم أعد الترحيل.'
                );
            }

            Schema::table('journal_entry_lines', function (Blueprint $table) {
                $table->foreign('entryID')
                    ->references('entryID')->on('journal_entries')
                    ->restrictOnDelete()->cascadeOnUpdate();
                $table->foreign('accountID')
                    ->references('accountID')->on('characcount')
                    ->restrictOnDelete()->cascadeOnUpdate();
                $table->foreign('coinsID')
                    ->references('coinsID')->on('coins')
                    ->nullOnDelete()->cascadeOnUpdate();
            });
        }
    }

    public function down(): void
    {
        // عكس السلوك غير مدعوم عمداً: العودة إلى cascadeOnDelete تعيد
        // ثغرة محو السندات المتسلسل (F-06). للرجوع يدوياً عدّل الهجرة.
    }
};
