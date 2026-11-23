<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * المستوى الثاني — صلاحية مفردة ديناميكية (slug مثل sales_invoices.create).
 * مصدر التهجرة: config/permissions.php عبر RolesPermissionsSeeder.
 */
class Permission extends Model
{
    protected $fillable = ['slug', 'module'];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permission');
    }
}
