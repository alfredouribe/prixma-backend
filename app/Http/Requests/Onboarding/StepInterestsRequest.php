<?php

namespace App\Http\Requests\Onboarding;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;

class StepInterestsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'interest_ids'   => 'array',
            'interest_ids.*' => 'uuid|exists:interests,id',
            'custom_interests' => 'nullable|string|max:200',
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $fromCatalog = count($this->input('interest_ids', []));
            // custom_interests cuenta como 1 interés adicional si está relleno
            $fromCustom = filled($this->input('custom_interests')) ? 1 : 0;
            $total = $fromCatalog + $fromCustom;

            if ($total < 3) {
                $validator->errors()->add('interest_ids', 'Debes seleccionar al menos 3 intereses.');
            }
        });
    }
}
