<?php

namespace App\Http\Controllers;

use App\Http\Requests\Public\TrackAffiliateClickRequest;
use App\Services\AffiliateAttributionService;
use Illuminate\Http\JsonResponse;

class AffiliatePublicController extends Controller
{
    public function trackClick(TrackAffiliateClickRequest $request): JsonResponse
    {
        AffiliateAttributionService::recordClick(
            $request->validated('ref'),
            $request->validated('path'),
            $request->ip(),
            $request->userAgent(),
        );

        return response()->json(['ok' => true]);
    }
}
