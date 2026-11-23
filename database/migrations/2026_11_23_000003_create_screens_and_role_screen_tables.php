<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المستوى الثاني (التوسعة) — الشاشات الديناميكية وصلاحيات الدور عليها.
 *
 * - screens: كل شاشات النظام (تُزرع من config/screens.php).
 * - role_screen: حالة كل دور على كل شاشة:
 *     view/create/update/delete/print + hidden (إخفاء الشاشة عن السايدبار).
 *
 * التوافق: غياب صف في role_screen = الشاشة متاحة للعرض افتراضياً
 * (حتى لا يفقد المستخدمون القدامى أي وصول قبل ضبط المصفوفة).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('screens', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->string('name', 120);
            $table->string('group', 60)->default('عام');
            $table->boolean('locked')->default(false)
                ->comment('شاشة أساسية لا تُخفى (اللوحة/الإعدادات)');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('role_screen', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('screen_id');
            $table->boolean('view')->default(true);
            $table->boolean('create')->default(false);
            $table->boolean('update')->default(false);
            $table->boolean('delete')->default(false);
            $table->boolean('print')->default(false);
            $table->boolean('hidden')->default(false)
                ->comment('مفعّلة = تختفي الشاشة من السايدبار لهذا الدور + منع خلفي');
            $table->timestamps();

            $table->unique(['role_id', 'screen_id']);
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->foreign('screen_id')->references('id')->on('screens')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_screen');
        Schema::dropIfExists('screens');
    }
};
