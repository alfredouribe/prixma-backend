<?php

namespace App\Http\Requests\Onboarding;

use Illuminate\Foundation\Http\FormRequest;

class UploadVideoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'video' => 'required|file|max:204800',
        ];
    }

    public function messages(): array
    {
        return [
            'video.required' => 'Debes adjuntar un archivo de video.',
            'video.file'     => 'El archivo enviado no es válido.',
            'video.max'      => 'El video no puede superar los 200 MB.',
        ];
    }
}
