<?php

namespace App\Http\Requests\Verification;

use Illuminate\Foundation\Http\FormRequest;

class SubmitVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'document' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:10240'],
            'selfie'   => ['sometimes', 'nullable', 'file', 'mimes:jpg,jpeg,png', 'max:10240'],
        ];
    }
}
