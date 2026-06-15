<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email'            => 'required|email|unique:users',
            'password'         => 'required|min:8|confirmed',
            'date_of_birth'    => 'required|date|before:-18 years',
            'terms_accepted'   => 'required|accepted',
            'privacy_accepted' => 'required|accepted',
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique'               => 'Este correo ya está registrado.',
            'email.email'                => 'Ingresa un correo válido.',
            'password.min'               => 'La contraseña debe tener al menos 8 caracteres.',
            'password.confirmed'         => 'Las contraseñas no coinciden.',
            'date_of_birth.before'       => 'Debes tener al menos 18 años para registrarte.',
            'terms_accepted.accepted'    => 'Debes aceptar los Términos de Uso.',
            'privacy_accepted.accepted'  => 'Debes aceptar la Política de Privacidad.',
        ];
    }
}
