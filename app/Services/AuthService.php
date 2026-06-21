<?php

namespace App\Services;

use App\Exceptions\AuthorizationException;
use App\Exceptions\BusinessException;
use App\Exceptions\UnauthorizedException;
use App\Mail\VerifyEmailMail;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

class AuthService
{
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

        Mail::to($user->email)->send(new VerifyEmailMail($user));

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
        Password::sendResetLink(['email' => $email]);
    }

    public function resetPassword(array $data): void
    {
        $status = Password::reset(
            [
                'email'    => $data['email'],
                'token'    => $data['token'],
                'password' => $data['password'],
            ],
            function (User $user, string $password) {
                $user->password = $password;
                $user->save();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw new BusinessException(match ($status) {
                Password::INVALID_TOKEN => 'El enlace de recuperación ha expirado o ya fue usado.',
                Password::INVALID_USER  => 'No se encontró el usuario.',
                default                 => 'No se pudo restablecer la contraseña.',
            });
        }
    }
}
