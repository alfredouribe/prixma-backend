<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Catálogo de paquetes otorgables (2026-08-11) — sin cobro real todavía, el
 * staff los crea/edita en Filament y los otorga manualmente a un usuario vía
 * `PackageService::grantToUser()`. Ver features/premium/specs/plan.md →
 * "Catálogo de paquetes" y domain.md → Package.
 */
class Package extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name',
        'description',
        'price',
        'grant_type',
        'grant_value',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'grant_value' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn ($model) => $model->id = Str::uuid());
    }
}
