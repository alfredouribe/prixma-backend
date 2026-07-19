<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'display_name'           => 'sometimes|string|max:50',
            'bio'                    => 'sometimes|nullable|string|max:300',
            'city'                   => 'sometimes|nullable|string|max:100',
            'latitude'               => 'sometimes|nullable|numeric|between:-90,90',
            'longitude'              => 'sometimes|nullable|numeric|between:-180,180',
            'intention'              => 'sometimes|in:partner,friendship,community,mentorship',
            'gender_identity_ids'    => 'sometimes|array',
            'gender_identity_ids.*'  => 'uuid|exists:gender_identities,id',
            'orientation_ids'        => 'sometimes|array',
            'orientation_ids.*'      => 'uuid|exists:orientations,id',
            'pronoun_ids'            => 'sometimes|array',
            'pronoun_ids.*'          => 'uuid|exists:pronouns,id',
            'interest_ids'           => 'sometimes|array',
            'interest_ids.*'         => 'uuid|exists:interests,id',
            'custom_gender_identity' => 'sometimes|nullable|string|max:100',
            'custom_orientation'     => 'sometimes|nullable|string|max:100',
            'custom_pronouns'        => 'sometimes|nullable|string|max:100',
            'custom_interests'       => 'sometimes|nullable|string|max:200',
        ];
    }
}
