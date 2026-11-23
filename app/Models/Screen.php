<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * شاشة ديناميكية — تُزرع من config/screens.php.
 * صلاحيات الدور عليها عبر role_screen (view/create/update/delete/print/hidden).
 */
class Screen extends Model
{
    protected $fillable = ['key', 'name', 'group', 'locked', 'sort'];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_screen')
            ->withPivot('view', 'create', 'update', 'delete', 'print', 'hidden')
            ->withTimestamps();
    }
}
