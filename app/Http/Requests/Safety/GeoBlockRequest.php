<?php

namespace App\Http\Requests\Safety;

use Illuminate\Foundation\Http\FormRequest;

class GeoBlockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'label'     => ['nullable', 'string', 'max:100'],
            'latitude'  => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'radius_km' => ['required', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'latitude.required'  => 'La latitud es requerida.',
            'longitude.required' => 'La longitud es requerida.',
            'radius_km.min'      => 'El radio mínimo es 1 km.',
            'radius_km.max'      => 'El radio máximo es 50 km.',
        ];
    }
}
