<?php

namespace App\Http\Requests\Matching;

use Illuminate\Foundation\Http\FormRequest;

class SwipeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $currentUserId = $this->user()->id;

        return [
            'swiped_id' => [
                'required',
                'uuid',
                'exists:users,id',
                "not_in:{$currentUserId}",
            ],
            'direction' => 'required|in:like,dislike,super_like',
        ];
    }

    public function messages(): array
    {
        return [
            'swiped_id.not_in' => 'No puedes swipear tu propio perfil.',
            'swiped_id.exists' => 'El perfil no existe.',
            'direction.in'     => 'Dirección inválida.',
        ];
    }
}
