<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    protected $table = 'units';
    protected $primaryKey = 'UnitID';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = false;

    protected $fillable = [
        'UnitName',
        'is_active',
        'conversion_factor',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'conversion_factor' => 'decimal:4',
    ];

    /** معامل التحويل الافتراضي عند غيابه = 1 (الوحدة تعادل نفسها) */
    public function factor(): float
    {
        return (float) ($this->conversion_factor ?? 1) > 0
            ? (float) ($this->conversion_factor ?? 1)
            : 1.0;
    }
}
