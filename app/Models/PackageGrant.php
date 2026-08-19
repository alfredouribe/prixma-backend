<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Log inmutable de cada vez que `PackageService::grantToUser()` otorga un
 * `Package` a un usuario — snapshot (`package_name`/`grant_type`/
 * `grant_value`), no solo FKs, para que el historial siga siendo correcto
 * aunque el `Package` original se edite o se borre después. Ver
 * features/premium/specs/plan.md → "Historial de paquetes otorgados".
 */
class PackageGrant extends Model
{
    public $timestamps = false;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'package_id',
        'admin_id',
        'package_name',
        'grant_type',
        'grant_value',
    ];

    protected function casts(): array
    {
        return [
            'grant_value' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function ($model) {
            $model->id = Str::uuid();
            $model->created_at = now();
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    public function admin()
    {
        return $this->belongsTo(Admin::class);
    }
}
