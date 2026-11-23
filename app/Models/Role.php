<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * المستوى الثاني — دور ديناميكي في قاعدة البيانات.
 *
 * الأدوار النظامية (admin/accountant/viewer) تُزرع من RolesPermissionsSeeder.
 * الصلاحيات مرتبطة عبر role_permission ويمكن تعديلها من شاشة
 * «إعدادات النظام → المستخدمون → الأدوار والصلاحيات».
 */
class Role extends Model
{
    protected $fillable = ['slug', 'name', 'is_system'];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permission');
    }

    /** صلاحيات الدور على كل شاشة (view/create/update/delete/print/hidden). */
    public function screens(): BelongsToMany
    {
        return $this->belongsToMany(Screen::class, 'role_screen')
            ->withPivot('view', 'create', 'update', 'delete', 'print', 'hidden')
            ->withTimestamps();
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }
}
