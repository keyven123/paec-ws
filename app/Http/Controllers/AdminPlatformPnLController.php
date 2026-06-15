<?php

namespace App\Http\Controllers;

use App\Services\AffiliateCommissionAvailabilityService;
use App\Services\Platform\AdminPlatformPnLService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class AdminPlatformPnLController extends Controller
{
    public function show(Request $request, AdminPlatformPnLService $platformPnLService): JsonResponse
    {
        $admin = auth('admin')->user();
        if (! $admin || ! $admin->role?->is_admin) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        $tz = AffiliateCommissionAvailabilityService::timezone();

        $asOf = null;
        $rawAsOf = $request->query('as_of');
        if ($rawAsOf !== null && $rawAsOf !== '') {
            try {
                $asOf = Carbon::parse((string) $rawAsOf, $tz);
            } catch (\Throwable) {
                return response()->json([
                    'message' => 'Invalid as_of date.',
                ], 422);
            }
        }

        $period = strtolower((string) $request->query('period', AdminPlatformPnLService::PERIOD_MONTHLY));
        if (! in_array($period, AdminPlatformPnLService::allowedPeriods(), true)) {
            return response()->json([
                'message' => 'Invalid period. Use daily, weekly, monthly, yearly, or custom.',
            ], 422);
        }

        $customStart = null;
        $customEnd = null;
        if ($period === AdminPlatformPnLService::PERIOD_CUSTOM) {
            $from = $request->query('custom_start', $request->query('date_from'));
            $to = $request->query('custom_end', $request->query('date_to'));
            if ($from === null || $from === '' || $to === null || $to === '') {
                return response()->json([
                    'message' => 'custom_start and custom_end are required when period is custom.',
                ], 422);
            }
            try {
                $customStart = Carbon::parse((string) $from, $tz)->startOfDay();
                $customEnd = Carbon::parse((string) $to, $tz)->endOfDay();
            } catch (\Throwable) {
                return response()->json([
                    'message' => 'Invalid custom_start or custom_end.',
                ], 422);
            }
        }

        try {
            $data = $platformPnLService->buildReport($asOf, $period, $customStart, $customEnd);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Platform P&L',
            'data' => $data,
        ]);
    }
}
