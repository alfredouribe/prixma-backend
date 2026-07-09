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
            'document_s3_key' => 'required|string|max:500',
            'selfie_s3_key'   => 'sometimes|nullable|string|max:500',
        ];
    }
}
