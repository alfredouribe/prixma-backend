<?php

namespace App\Http\Requests\Notifications;

use Illuminate\Foundation\Http\FormRequest;

class RegisterDeviceTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'platform' => ['required', 'in:ios,android'],
        ];
    }

    public function messages(): array
    {
        return [
            'token.required' => 'El token del dispositivo es requerido.',
            'platform.required' => 'La plataforma es requerida.',
            'platform.in' => 'Plataforma inválida.',
        ];
    }
}
