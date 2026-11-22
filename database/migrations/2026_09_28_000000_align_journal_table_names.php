<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الجزء الثاني من توحيد أسماء الجداول: جداول القيود المحاسبية.
 *
 * الجدول JournalEntrryLine (بحرف r مكرر في اسمه) يُشار إليه في الكود
 * والتقارير، وأصبح بعد هذا الملف journal_entry_lines (تسمية قياسية)،
 * وJournal_Entries أصبح journal_entries — مطابقة لنماذج Eloquent.
 *
 * الحماية: idempotent (يعمل فقط إذا كان الاسم القديم موجوداً والجديد غائباً)،
 * لذلك لا يفعل شيئاً على التثبيت الجديد.
 *
 * التوقيع 2026_09_28_000000 مقصود: قبل أول هجرة لاحقة تربط FK بـ
 * 'journal_entries' (2026_09_29_214543).
 */
return new class extends Migration
{
    private const RENAMES = [
        'Journal_Entries'   => 'journal_entries',
        'JournalEntrryLine' => 'journal_entry_lines',
    ];

    public function up(): void
    {
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
