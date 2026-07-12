<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'selfie_verification_enabled' => 'sometimes|boolean',
            'incognito_mode_enabled'      => 'sometimes|boolean',
            'geo_block_enabled'           => 'sometimes|boolean',
            'reports_enabled'             => 'sometimes|boolean',
            'notify_matches_enabled'      => 'sometimes|boolean',
            'notify_messages_enabled'     => 'sometimes|boolean',
            'notify_events_enabled'       => 'sometimes|boolean',
        ];
    }
}
