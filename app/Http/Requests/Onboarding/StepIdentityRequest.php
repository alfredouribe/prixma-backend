<?php

namespace App\Http\Requests\Onboarding;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;

class StepIdentityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'display_name'                     => 'required|string|max:50',
            'gender_identity_ids'               => 'array',
            'gender_identity_ids.*'             => 'uuid|exists:gender_identities,id',
            'custom_gender_identity'            => 'nullable|string|max:100',
            'orientation_ids'                   => 'array',
            'orientation_ids.*'                 => 'uuid|exists:orientations,id',
            'custom_orientation'                => 'nullable|string|max:100',
            'interested_in_all_genders'         => 'required|boolean',
            'interested_gender_identity_ids'    => 'array',
            'interested_gender_identity_ids.*'  => 'uuid|exists:gender_identities,id',
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $hasGender = !empty($this->input('gender_identity_ids')) || filled($this->input('custom_gender_identity'));
            if (!$hasGender) {
                $validator->errors()->add('gender_identity_ids', 'Debes seleccionar al menos una identidad de género o describirte con tus propias palabras.');
            }

            $hasOrientation = !empty($this->input('orientation_ids')) || filled($this->input('custom_orientation'));
            if (!$hasOrientation) {
                $validator->errors()->add('orientation_ids', 'Debes seleccionar al menos una orientación sexual o describirte con tus propias palabras.');
            }

            // "¿A quién te gustaría conocer?" — obligatorio, mismo nivel que
            // identidad/orientación (ver spec.md → "Compatibilidad de
            // género"): debe marcarse "Todes" o al menos una identidad del
            // catálogo.
            $interestedInAllGenders = $this->boolean('interested_in_all_genders');
            $hasInterestedGenders   = !empty($this->input('interested_gender_identity_ids'));
            if (!$interestedInAllGenders && !$hasInterestedGenders) {
                $validator->errors()->add('interested_gender_identity_ids', 'Debes seleccionar "Todes / sin preferencia" o al menos una identidad de género de quienes te gustaría conocer.');
            }
        });
    }
}
