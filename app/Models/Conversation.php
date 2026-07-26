<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Conversation extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'user_id_1',
        'user_id_2',
        'type',
        'status',
        'match_id',
    ];

    protected static function booted(): void
    {
        static::creating(fn($model) => $model->id = Str::uuid());
    }

    public function user1()
    {
        return $this->belongsTo(User::class, 'user_id_1');
    }

    public function user2()
    {
        return $this->belongsTo(User::class, 'user_id_2');
    }

    public function match()
    {
        return $this->belongsTo(UserMatch::class, 'match_id');
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    public function latestMessage()
    {
        return $this->hasOne(Message::class)->latestOfMany('created_at');
    }

    /**
     * Filtra conversaciones en las que el usuario dado participa, sin
     * importar si es `user_id_1` o `user_id_2` — ver features/chat/specs/tasks.md.
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $q) use ($user) {
            $q->where('user_id_1', $user->id)->orWhere('user_id_2', $user->id);
        });
    }

    /**
     * Filtra la conversación entre dos usuarios específicos, sin importar el
     * orden en que se pasen los IDs. Mismo criterio de orden que
     * `ChatService::sortIds()`/`MatchingService` (`user_id_1` siempre el
     * UUID menor). Usado por `SafetyService::createReport()` para adjuntar
     * evidencia de chat al reporte — ver
     * features/safety/specs/plan.md → "Reporte con bloqueo automático".
     */
    public function scopeBetweenUsers(Builder $query, string $userId1, string $userId2): Builder
    {
        [$id1, $id2] = $userId1 < $userId2 ? [$userId1, $userId2] : [$userId2, $userId1];

        return $query->where('user_id_1', $id1)->where('user_id_2', $id2);
    }
}
