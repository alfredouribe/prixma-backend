<?php

namespace App\Http\Requests\Events;

use Illuminate\Foundation\Http\FormRequest;

class RsvpEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'in:interested,going,not_going'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'El estado de asistencia es requerido.',
            'status.in'        => 'Estado de asistencia inválido.',
        ];
    }
}
