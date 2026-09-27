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
use Illuminate\Support\Facades\Mail;

class AuthService
{
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
}
