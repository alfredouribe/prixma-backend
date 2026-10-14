<?php

namespace App\Http\Requests\Feedback;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // la autorización la resuelve el middleware auth:sanctum
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['bug', 'comment'])],
            'message' => ['required', 'string', 'max:2000'],
            'app_version' => ['nullable', 'string', 'max:20'],
            'platform' => ['nullable', Rule::in(['ios', 'android'])],
        ];
    }

    public function messages(): array
    {
        return [
            'type.required' => 'El tipo de reporte es obligatorio.',
            'type.in' => 'Tipo de reporte inválido.',
            'message.required' => 'El mensaje es obligatorio.',
            'message.max' => 'El mensaje no puede superar los 2000 caracteres.',
            'platform.in' => 'Plataforma inválida.',
        ];
    }
}
