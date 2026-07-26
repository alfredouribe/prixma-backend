<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Event extends Model
{
    use HasFactory, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'creator_id',
        'title',
        'description',
        'category',
        'event_date',
        'location_name',
        'latitude',
        'longitude',
        'external_link',
        'image_url',
        'image_key',
    ];

    protected function casts(): array
    {
        return [
            'event_date' => 'datetime',
            'latitude'   => 'decimal:8',
            'longitude'  => 'decimal:8',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn ($model) => $model->id = Str::uuid());
    }

    /**
     * Cuenta admin (staff) que creó el evento — nunca un User final, ver
     * domain.md → Event (2026-07-26).
     */
    public function creator()
    {
        return $this->belongsTo(Admin::class, 'creator_id');
    }

    public function rsvps()
    {
        return $this->hasMany(EventRsvp::class);
    }
}
