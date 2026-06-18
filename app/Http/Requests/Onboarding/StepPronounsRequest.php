<?php

namespace App\Http\Requests\Onboarding;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;

class StepPronounsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pronoun_ids'    => 'array',
            'pronoun_ids.*'  => 'uuid|exists:pronouns,id',
            'custom_pronouns' => 'nullable|string|max:100',
            'photo_url'      => 'nullable|string|url|max:500',
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $hasPronouns = !empty($this->input('pronoun_ids')) || filled($this->input('custom_pronouns'));
            if (!$hasPronouns) {
                $validator->errors()->add('pronoun_ids', 'Debes seleccionar al menos un pronombre o describirlo con tus propias palabras.');
            }
        });
    }
}
