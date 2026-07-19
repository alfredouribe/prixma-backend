<?php

namespace App\Http\Requests\Safety;

use Illuminate\Foundation\Http\FormRequest;

/**
 * No listado explícitamente en plan.md → "Archivos" (solo ReportRequest y
 * GeoBlockRequest aparecen ahí), pero constitution.md → "Backend
 * Architecture Rules" exige que toda validación viva en un Form Request —
 * nunca inline en el controller. El endpoint POST /api/safety/blocks
 * recibe `blocked_id`, así que necesita su propio Form Request.
 */
class BlockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $currentUserId = $this->user()->id;

        return [
            'blocked_id' => [
                'required',
                'uuid',
                'exists:users,id',
                "not_in:{$currentUserId}",
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'blocked_id.not_in' => 'No puedes bloquearte a ti mismo.',
            'blocked_id.exists' => 'El perfil no existe.',
        ];
    }
}
