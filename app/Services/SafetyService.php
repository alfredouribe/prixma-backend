<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Mail\UserBannedMail;
use App\Models\Block;
use App\Models\Conversation;
use App\Models\GeographicBlock;
use App\Models\Message;
use App\Models\Report;
use App\Models\User;
use App\Models\UserMatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class SafetyService
{
    /**
     * Crea un reporte y bloquea automáticamente al reportado en la misma
     * transacción atómica (cambio de comportamiento confirmado con el
     * humano 2026-07-23 — ver features/safety/specs/plan.md → "Reporte con
     * bloqueo automático"). Cada llamada crea una fila nueva (ya no es
     * idempotente vía updateOrCreate): cada reporte adjunta su propia
     * evidencia (snapshot del perfil reportado + hasta 20 mensajes previos
     * entre ambos) tal como se veían en el momento de ESE reporte.
     * Reusa `blockUser()` tal cual existe (anula el `UserMatch` + oculta la
     * `Conversation`) en vez de reimplementar el bloqueo a mano, así un
     * bloqueo por reporte se comporta idéntico a uno manual.
     */
    public function createReport(User $reporter, string $reportedId, array $data): Report
    {
        if ($reporter->id === $reportedId) {
            throw new BusinessException('No puedes reportarte a ti mismo.');
        }

        return DB::transaction(function () use ($reporter, $reportedId, $data) {
            $reportedUser = User::with([
                'profile.photos',
                'profile.genderIdentities',
                'profile.orientations',
                'profile.pronouns',
                'profile.interests',
            ])->findOrFail($reportedId);

            $conversation = Conversation::betweenUsers($reporter->id, $reportedId)->first();

            $messages = $conversation
                ? $conversation->messages()->latest()->limit(20)->get()->reverse()->values()
                : collect();

            $report = Report::create([
                'reporter_id'      => $reporter->id,
                'reported_id'      => $reportedId,
                'reason'           => $data['reason'],
                'description'      => $data['description'] ?? null,
                'profile_snapshot' => $reportedUser->profile?->toSnapshotArray() ?? [],
                'chat_snapshot'    => $messages->toArray(),
                'status'           => 'pending',
            ]);

            // Reusa blockUser() completo (Block::firstOrCreate + anula UserMatch
            // + oculta Conversation) — mismo comportamiento que un bloqueo
            // manual, decisión confirmada con el humano.
            $this->blockUser($reporter, $reportedId);

            return $report;
        });
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

    /**
     * Transiciones de estado de un reporte — solo las usa el panel admin
     * (`ReportResource`, ver features/safety/specs/plan.md → "Panel admin —
     * gestión de reportes"). No hay endpoint móvil que las exponga.
     * `domain.md` → `Report` no define `reviewed_by`/`reviewed_at` (a
     * diferencia de `VerificationRequest`), así que la transición es solo
     * un cambio de `status`, sin auditoría de quién revisó.
     */
    public function markReportAsReviewed(Report $report): Report
    {
        $report->update(['status' => 'reviewed']);

        return $report->fresh();
    }

    public function markReportAsResolved(Report $report): Report
    {
        $report->update(['status' => 'resolved']);

        return $report->fresh();
    }

    /**
     * Sanción real sobre el usuario reportado — decisión confirmada con el
     * humano 2026-08-03, ver features/safety/specs/plan.md → "Banear desde
     * un reporte". A diferencia de `markReportAsResolved()` (que no aplica
     * ninguna sanción), esto sí actúa sobre la cuenta:
     * 1. `users.status = 'banned'` — ya bloqueaba el login (`AuthService`),
     *    pero no invalidaba una sesión ya activa; se revocan también todos
     *    sus tokens de Sanctum para que el bloqueo sea inmediato, no solo en
     *    el siguiente intento de login.
     * 2. El reporte que originó el ban se marca `resolved` — banear es en sí
     *    la resolución más fuerte posible, no tiene sentido dejarlo abierto.
     * 3. Correo al usuario (fuera de la transacción, best-effort — mismo
     *    criterio que las notificaciones de Matching/Chat: un fallo en el
     *    envío nunca debe revertir un ban ya aplicado).
     *
     * `$reasonLabel` ya viene resuelto por el llamador (`ReportResource::
     * reasonLabels()`) — el Service no depende de una clase de Filament
     * (capa de presentación).
     */
    public function banReportedUser(Report $report, string $reasonLabel): Report
    {
        $report = DB::transaction(function () use ($report) {
            $user = $report->reported;
            $user->update(['status' => 'banned']);
            $user->tokens()->delete();

            $report->update(['status' => 'resolved']);

            return $report->fresh();
        });

        Mail::to($report->reported->email)->queue(new UserBannedMail($report->reported, $reasonLabel));

        return $report;
    }

    /**
     * Revierte un ban (apelación aceptada vía support@prixma.site — no hay
     * flujo automatizado de apelación, es un cambio manual del staff).
     * No reenvía ningún correo ni reactiva tokens viejos (ya fueron
     * revocados, el usuario simplemente vuelve a poder iniciar sesión).
     */
    public function unbanUser(User $user): User
    {
        $user->update(['status' => 'active']);

        return $user->fresh();
    }

    /**
     * Fotos del perfil del usuario reportado — para el detalle del reporte
     * en el panel admin (ver features/safety/specs/plan.md → "Panel admin —
     * gestión de reportes" → "Fotos del perfil reportado"). Devuelve una
     * colección vacía (nunca null) si el reportado no completó su perfil o
     * no subió fotos, para que la Page no tenga que manejar ese caso.
     */
    public function getReportedUserPhotos(Report $report): Collection
    {
        return $report->reported->profile?->photos ?? collect();
    }

    /**
     * Hasta `$limit` mensajes — los más recientes primero, reordenados a
     * cronológico ascendente al final — de TODAS las conversaciones donde
     * participó el usuario reportado, filtrados al mismo día calendario en
     * que se creó el reporte (`report.created_at`). Incluye ambos lados de
     * cada conversación (lo que escribió el reportado y lo que escribió la
     * otra persona) y puede abarcar más de una conversación si el reportado
     * tuvo actividad en varias ese día — alcance confirmado con el humano,
     * ver features/safety/specs/plan.md.
     *
     * Usa el scope `Conversation::forUser()` ya existente (mismo que usa
     * Chat) para encontrar las conversaciones del reportado en vez de
     * andar armando la condición user_id_1/user_id_2 a mano aquí. Los
     * mensajes soft-deleted quedan excluidos automáticamente por el default
     * scope de `Message` (SoftDeletes) — nunca se usa `withTrashed()`.
     */
    public function getReportedUserMessagesForReportDate(Report $report, int $limit = 20): Collection
    {
        $reportedUser = $report->reported;
        $reportDate = $report->created_at->toDateString();

        $conversationIds = Conversation::forUser($reportedUser)->pluck('id');

        return Message::whereIn('conversation_id', $conversationIds)
            ->whereDate('created_at', $reportDate)
            ->with('sender.profile')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->sortBy('created_at')
            ->values();
    }
}
