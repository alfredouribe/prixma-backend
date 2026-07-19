<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

class SendRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $currentUserId = $this->user()->id;

        return [
            'receiver_id' => ['required', 'uuid', 'exists:users,id', "not_in:{$currentUserId}"],
            'content' => ['required', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'receiver_id.not_in' => 'No puedes enviarte una solicitud a ti mismo.',
            'receiver_id.exists' => 'El perfil no existe.',
            'content.required' => 'El mensaje no puede estar vacío.',
            'content.max' => 'El mensaje no puede superar los 500 caracteres.',
        ];
    }
}
