<?php

namespace App\Http\Requests\Matching;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMatchingPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'age_min'           => 'sometimes|integer|min:18|max:99|lte:age_max',
            'age_max'           => 'sometimes|integer|min:18|max:99|gte:age_min',
            'max_distance_km'   => 'sometimes|integer|min:1|max:300',
            'intentions'        => 'sometimes|nullable|array',
            'intentions.*'      => 'string|in:partner,friendship,community,mentorship',
            'gender_identities' => 'sometimes|nullable|array',
            'gender_identities.*' => 'string',
            'orientations'      => 'sometimes|nullable|array',
            'orientations.*'    => 'string',
            'verified_only'     => 'sometimes|boolean',
            'has_video_only'    => 'sometimes|boolean',
        ];
    }
}
