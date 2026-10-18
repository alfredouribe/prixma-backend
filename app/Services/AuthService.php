<?php

namespace App\Services;

use App\Exceptions\AuthorizationException;
use App\Exceptions\BusinessException;
use App\Exceptions\UnauthorizedException;
use App\Mail\PasswordResetCodeMail;
use App\Mail\VerifyEmailMail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class AuthService
{
    public function __construct(
        private readonly PackageService $packageService,
        private readonly VerificationService $verificationService,
    ) {}

    // Corto a propósito porque es un código de 6 dígitos que se teclea a
    // mano (no un link de 60 caracteres) — mismo criterio que cualquier OTP;
    // la ruta ya está bajo `throttle:auth` (5 intentos/min/IP) así que un
    // código de vida corta más ese límite hacen inviable un ataque de fuerza
    // bruta. Reemplaza el flujo con `Password::sendResetLink()`/`Password::
    // reset()` (pensado para un link, no para que el usuario copie un token
    // de 60 caracteres a mano) — bug real reportado por el humano 2026-09-27:
    // el correo mostraba un botón/link pero la app pedía un código.
    private const CODE_EXPIRY_MINUTES = 15;

    public function register(array $data): array
    {
        $user = User::create([
            'email'               => $data['email'],
            'password'            => $data['password'],
            'date_of_birth'       => $data['date_of_birth'],
            'terms_accepted_at'   => now(),
            'privacy_accepted_at' => now(),
            'status'              => 'active',
        ]);

        $token = $user->createToken('mobile')->plainTextToken;

        Mail::to($user->email)->queue(new VerifyEmailMail($user));

        // Best-effort: un fallo aquí (ej. `PlatformSetting::current()` no
        // pudo leer/crear la fila) nunca debe romper el registro de la
        // cuenta — mismo criterio ya usado en el proyecto para
        // notificaciones no críticas.
        try {
            $this->packageService->grantWelcomeTrialIfEnabled($user);
        } catch (\Throwable $e) {
            Log::error('No se pudo otorgar el trial de bienvenida al registrar la cuenta.', [
                'user_id' => $user->id,
                'exception' => $e->getMessage(),
            ]);
        }

        return ['user' => $user, 'token' => $token];
    }

    public function verifyEmail(string $id, string $hash): void
    {
        $user = User::findOrFail($id);

        abort_unless(
            hash_equals(sha1($user->email), $hash),
            403,
            'Enlace de verificación inválido.'
        );

        if (! $user->email_verified_at) {
            $user->email_verified_at = now();
            $user->save();
        }
    }

    public function login(array $data): array
    {
        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw new UnauthorizedException('Correo o contraseña incorrectos.');
        }

        if ($user->status === 'banned') {
            throw new AuthorizationException('Tu cuenta ha sido deshabilitada por violar los términos de uso.');
        }

        if ($user->status === 'suspended') {
            throw new AuthorizationException('Tu cuenta ha sido suspendida temporalmente.');
        }

        $token = $user->createToken('mobile')->plainTextToken;

        return ['user' => $user, 'token' => $token];
    }

    public function logout(User $user): void
    {
        /** @var \Laravel\Sanctum\PersonalAccessToken $token */
        $token = $user->currentAccessToken();
        $token->delete();
    }

    public function forgotPassword(string $email): void
    {
        $user = User::where('email', $email)->first();

        // Nunca revelar si el correo existe (constitution.md) — si no hay
        // usuario, simplemente no se guarda ni se encola nada, y el
        // controlador ya responde el mismo mensaje genérico en ambos casos.
        if (! $user) {
            return;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            ['token' => Hash::make($code), 'created_at' => now()]
        );

        Mail::to($email)->queue(new PasswordResetCodeMail($code));
    }

    public function resetPassword(array $data): void
    {
        $record = DB::table('password_reset_tokens')->where('email', $data['email'])->first();

        // `diffInMinutes(..., true)` — el segundo argumento (`$absolute`) es
        // obligatorio aquí: Carbon 3 (instalado en este proyecto) cambió el
        // default de `diffInMinutes()` de absoluto a con signo, a diferencia
        // de Carbon 2. Sin `true` explícito, la diferencia contra una fecha
        // pasada da negativa y esta condición nunca detecta un código
        // vencido — bug real encontrado por el test "rechaza un código
        // expirado" al escribir este flujo (2026-09-27).
        if (! $record || now()->diffInMinutes($record->created_at, true) > self::CODE_EXPIRY_MINUTES) {
            throw new BusinessException('El código expiró o es inválido. Solicita uno nuevo.');
        }

        if (! Hash::check($data['token'], $record->token)) {
            throw new BusinessException('El código ingresado es incorrecto.');
        }

        $user = User::where('email', $data['email'])->first();

        if (! $user) {
            throw new BusinessException('No se encontró el usuario.');
        }

        $user->password = $data['password'];
        $user->save();

        // De un solo uso — mismo criterio que el broker de Laravel que
        // reemplaza este flujo.
        DB::table('password_reset_tokens')->where('email', $data['email'])->delete();
    }

    /**
     * Elimina la cuenta a solicitud del propio usuario (Google Play exige
     * una forma real de hacerlo; ver `../legal/aviso-de-privacidad.md` línea
     * 103, que ya lo prometía sin que existiera todavía). `domain.md` →
     * `User`: "No se puede eliminar físicamente — soft delete siempre", así
     * que el registro nunca se borra de verdad (`$user->delete()` usa el
     * trait `SoftDeletes` ya aplicado). Los binarios en S3/disco local sí
     * deben desaparecer de verdad — no hay razón de negocio ni legal para
     * retenerlos tras una eliminación solicitada por el usuario, a
     * diferencia del registro mismo.
     *
     * Orden (dentro de una única transacción — si cualquier paso falla,
     * nada queda a medias; los borrados de S3/disco local no son
     * transaccionales por naturaleza, pero no lanzan si el archivo ya no
     * existe, así que no bloquean el rollback):
     * 1. Revoca todos los tokens Sanctum (mismo patrón que
     *    `SafetyService::banReportedUser()`) — una sesión activa deja de
     *    servir de inmediato, no solo en el siguiente login.
     * 2. Borra los archivos reales en S3: fotos de perfil (cada
     *    `ProfilePhoto.key`), video de presentación procesado y su
     *    thumbnail (`profile.video_url`/`video_thumbnail_url` — pese al
     *    nombre, `video_url` guarda la key de S3, no una URL, ver
     *    `ProfileService::saveVideo()`/`ProcessProfileVideo`), y el
     *    documento/selfie de verificación si siguen en el disco local
     *    (`VerificationService::deleteAllFilesForProfile()` — normalmente
     *    solo quedan si la última solicitud nunca fue aprobada/rechazada).
     * 3. Borra los `device_tokens` del usuario (hard delete real, no tiene
     *    sentido retener tokens de push de una cuenta eliminada).
     * 4. Soft-delete del `User` — lo saca de cualquier query normal del
     *    resto de la app (Explorar, Chat, Matches, etc. ya usan
     *    `User::query()`/relaciones sin `withTrashed()`, sin necesitar
     *    cambios). No se soft-eliminan `Profile` ni el resto de tablas
     *    relacionadas a propósito: casi todo acceso pasa por `User`
     *    primero, y el soft-delete de `User` ya los vuelve inalcanzables en
     *    la práctica.
     *
     * No borra mensajes ni conversaciones con otros usuarios — mismo
     * criterio que cuando alguien bloquea a otro
     * (`SafetyService::blockUser()`): el historial del otro lado se queda
     * intacto.
     */
    public function deleteAccount(User $user): void
    {
        DB::transaction(function () use ($user) {
            $user->tokens()->delete();

            $profile = $user->profile;

            if ($profile) {
                $profile->photos->each(
                    fn ($photo) => Storage::disk('s3')->delete($photo->key)
                );

                foreach (array_filter([$profile->video_url, $profile->video_thumbnail_url]) as $videoKey) {
                    Storage::disk('s3')->delete($videoKey);
                }

                $this->verificationService->deleteAllFilesForProfile($profile);
            }

            $user->deviceTokens()->delete();

            $user->delete();
        });
    }
}
