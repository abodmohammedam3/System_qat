<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * نموذج المستخدم — كان مفقوداً بالكامل من المشروع (الفحص F-01):
 * جدول users موجود في الهجرات وUserFactory موجود وDatabaseSeeder
 * يشير إلى App\Models\User — لكن الملف نفسه لم يُنشأ يوماً،
 * ما جعل المصادقة والصلاحيات والحقول التدقيقية (created_by/updated_by)
 * كلها ميتة.
 */
class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_ADMIN      = 'admin';
    public const ROLE_ACCOUNTANT = 'accountant';
    public const ROLE_VIEWER     = 'viewer';

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'is_active'         => 'boolean',
            'last_login_at'     => 'datetime',
        ];
    }

    // =========================================================
    // الأدوار والصلاحيات
    // =========================================================

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isViewer(): bool
    {
        return $this->role === self::ROLE_VIEWER;
    }

    /**
     * الصلاحيات المسموحة للدور (مطابقة لمفاتيح config/permissions.php).
     * admin → كل شيء. viewer → عرض وطباعة فقط. accountant → كل العمليات
     * التشغيلية عدا إدارة المستخدمين والنسخ الاحتياطي.
     */
    public function rolePermissions(): array
    {
        if ($this->isAdmin()) {
            return ['*'];
        }

        $moduleActions = [
            'purchase_invoices', 'purchase_returns', 'sales_invoices',
            'sales_returns', 'payment_vouchers', 'receipt_vouchers',
        ];

        if ($this->isViewer()) {
            $perms = [];
            foreach ($moduleActions as $m) {
                $perms[] = "{$m}.view";
                $perms[] = "{$m}.print";
            }

            return $perms;
        }

        // accountant: كل عمليات الموديولات التشغيلية
        $perms = [];
        foreach ($moduleActions as $m) {
            foreach (['view', 'create', 'update', 'delete', 'post', 'unpost', 'print'] as $a) {
                $perms[] = "{$m}.{$a}";
            }
        }

        return $perms;
    }
}
