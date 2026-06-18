<?php

namespace App\Http\Requests\Onboarding;

use Illuminate\Foundation\Http\FormRequest;

class StepSafetyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'selfie_verification_enabled' => 'required|boolean',
            'incognito_mode_enabled'      => 'required|boolean',
            'geo_block_enabled'           => 'required|boolean',
            'reports_enabled'             => 'required|boolean',
        ];
    }
}
