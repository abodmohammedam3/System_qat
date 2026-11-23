<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Screen;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

/**
 * يزرع جدول screens من config/screens.php (المصدر الوحيد).
 * يشغَّل تلقائياً بعد كل تحديث للسجل — idempotent عبر updateOrCreate.
 */
class ScreensSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('screens')) {
            return;
        }

        foreach (config('screens', []) as $i => $row) {
            Screen::updateOrCreate(
                ['key' => $row['key']],
                [
                    'name'   => $row['name'],
                    'group'  => $row['group'],
                    'locked' => (bool) ($row['locked'] ?? false),
                    'sort'   => $i,
                ]
            );
        }
    }
}
