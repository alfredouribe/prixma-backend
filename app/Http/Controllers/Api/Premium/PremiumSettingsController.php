<?php

namespace App\Http\Controllers\Api\Premium;

use App\Http\Controllers\Controller;
use App\Http\Resources\PremiumSettingsResource;
use Illuminate\Http\Request;

class PremiumSettingsController extends Controller
{
    public function show(Request $request): PremiumSettingsResource
    {
        return new PremiumSettingsResource($request->user());
    }
}
