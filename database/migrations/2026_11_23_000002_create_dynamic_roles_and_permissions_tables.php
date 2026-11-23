<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المستوى الثاني — أدوار وصلاحيات ديناميكية في قاعدة البيانات.
 *
 * - roles: الأدوار (admin/accountant/viewer تُزرع كأدوار نظامية is_system).
 * - permissions: كل صلاحية مفردة (slug مثل purchase_invoices.create).
 * - role_permission: pivot يحدد صلاحيات كل دور — قابلة للتعديل من الشاشة.
 * - users.role_id: ربط المستخدم بدوره (عمود role النصي يبقى fallback).
 *
 * التوافق الخلفي: User::rolePermissions() يقرأ من العلاقة أولاً، ويرجع
 * للخريطة الثابتة القديمة فقط عند غياب role_id — لا شيء ينكسر.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique()->comment('المعرف البرمجي: admin/accountant/viewer');
            $table->string('name', 100)->comment('الاسم المعروض');
            $table->boolean('is_system')->default(false)
                ->comment('دور نظامي: لا يُحذف ولا تُعدل صلاحيات admin');
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique()->comment('مثل: sales_invoices.create');
            $table->string('module', 60)->comment('الموديول: sales_invoices ...');
            $table->timestamps();
        });

        Schema::create('role_permission', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
            $table->primary(['role_id', 'permission_id']);

            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'role_id')) {
                $table->foreignId('role_id')->nullable()->after('role')
                    ->constrained('roles')->nullOnDelete()
                    ->comment('الدور الديناميكي — role النصي fallback');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'role_id')) {
                $table->dropForeign(['role_id']);
                $table->dropColumn('role_id');
            }
        });

        Schema::dropIfExists('role_permission');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
