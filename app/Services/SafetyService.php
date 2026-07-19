<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Block;
use App\Models\Conversation;
use App\Models\GeographicBlock;
use App\Models\Report;
use App\Models\User;
use App\Models\UserMatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SafetyService
{
    /**
     * Crea o actualiza un reporte. Se usa `updateOrCreate` sobre
     * (reporter_id, reported_id) para que un reporte repetido del mismo
     * usuario al mismo objetivo nunca lance un error (idempotente) y para
     * que "solo se guarde el más reciente" (domain.md → Report, política
     * elegida entre las dos permitidas ahí) — reabre la revisión con
     * status `pending` aunque el reporte anterior ya hubiera sido
     * revisado/resuelto.
     */
    public function reportUser(User $reporter, array $data): Report
    {
        if ($reporter->id === $data['reported_id']) {
            throw new BusinessException('No puedes reportarte a ti mismo.');
        }

        return Report::updateOrCreate(
            [
                'reporter_id' => $reporter->id,
                'reported_id' => $data['reported_id'],
            ],
            [
                'reason'      => $data['reason'],
                'description' => $data['description'] ?? null,
                'status'      => 'pending',
            ]
        );
    }

    /**
     * Bloquea a un usuario. Dentro de una transacción (ver
     * features/safety/specs/plan.md → "Integración con Chat y Matches"):
     * 1. Crea el registro en `blocks` (idempotente vía firstOrCreate).
     * 2. Anula el `UserMatch` existente entre ambos, si existe.
     * 3. Marca la `Conversation` existente entre ambos como `blocked`
     *    (no elimina mensajes ni la fila).
     */
    public function blockUser(User $blocker, string $blockedId): Block
    {
        if ($blocker->id === $blockedId) {
            throw new BusinessException('No puedes bloquearte a ti mismo.');
        }

        return DB::transaction(function () use ($blocker, $blockedId) {
            $block = Block::firstOrCreate([
                'blocker_id' => $blocker->id,
                'blocked_id' => $blockedId,
            ]);

            // Mismo criterio de orden que MatchingService/Conversation:
            // user_id_1 es siempre el UUID menor.
            [$id1, $id2] = $blocker->id < $blockedId
                ? [$blocker->id, $blockedId]
                : [$blockedId, $blocker->id];

            UserMatch::where('user_id_1', $id1)
                ->where('user_id_2', $id2)
                ->delete();

            Conversation::where('user_id_1', $id1)
                ->where('user_id_2', $id2)
                ->update(['status' => 'blocked']);

            return $block;
        });
    }

    public function unblockUser(User $user, string $blockId): void
    {
        Block::where('id', $blockId)
            ->where('blocker_id', $user->id)
            ->firstOrFail()
            ->delete();
    }

    public function getBlocks(User $user): Collection
    {
        return Block::where('blocker_id', $user->id)
            ->with('blocked.profile.photos')
            ->orderByDesc('created_at')
            ->get();
    }

    public function getGeoBlocks(User $user): Collection
    {
        return GeographicBlock::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->get();
    }

    public function createGeoBlock(User $user, array $data): GeographicBlock
    {
        return GeographicBlock::create([
            'user_id'   => $user->id,
            'label'     => $data['label'] ?? null,
            'latitude'  => $data['latitude'],
            'longitude' => $data['longitude'],
            'radius_km' => $data['radius_km'],
        ]);
    }

    public function deleteGeoBlock(User $user, string $geoBlockId): void
    {
        GeographicBlock::where('id', $geoBlockId)
            ->where('user_id', $user->id)
            ->firstOrFail()
            ->delete();
    }
}
