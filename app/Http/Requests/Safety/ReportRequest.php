<?php

namespace App\Http\Requests\Safety;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $currentUserId = $this->user()->id;

        return [
            'reported_id' => [
                'required',
                'uuid',
                'exists:users,id',
                "not_in:{$currentUserId}",
            ],
            'reason' => [
                'required',
                Rule::in(['harassment', 'discrimination', 'fake_profile', 'inappropriate_content', 'other']),
            ],
            'description' => ['nullable', 'string', 'max:300'],
        ];
    }

    public function messages(): array
    {
        return [
            'reported_id.not_in'    => 'No puedes reportarte a ti mismo.',
            'reported_id.exists'    => 'El perfil no existe.',
            'reason.in'             => 'Motivo inválido.',
            'description.max'       => 'La descripción no puede superar los 300 caracteres.',
        ];
    }
}
