<?php

namespace App\Http\Controllers;

use App\Services\AffiliateCommissionAvailabilityService;
use App\Services\Platform\TransactionCommissionLedgerService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCommissionLedgerController extends Controller
{
    /**
     * Returns aggregated transaction_commissions for the given date range.
     * The frontend feeds in the resolved `current_range` from the existing
     * platform-pnl response so both views always show the same window.
     *
     * GET /admin/finance/commission-ledger?from=YYYY-MM-DD&to=YYYY-MM-DD
     */
    public function show(Request $request, TransactionCommissionLedgerService $service): JsonResponse
    {
        $admin = auth('admin')->user();
        if (! $admin || ! $admin->role?->is_admin) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        $tz = AffiliateCommissionAvailabilityService::timezone();

        $rawFrom = (string) $request->query('from', '');
        $rawTo = (string) $request->query('to', '');
        if ($rawFrom === '' || $rawTo === '') {
            return response()->json([
                'message' => 'from and to are required (ISO date or datetime).',
            ], 422);
        }

        try {
            $from = Carbon::parse($rawFrom, $tz);
            $to = Carbon::parse($rawTo, $tz);
        } catch (\Throwable) {
            return response()->json([
                'message' => 'Invalid from or to.',
            ], 422);
        }

        // Treat bare YYYY-MM-DD as inclusive-by-day for convenience.
        if (! str_contains($rawFrom, 'T') && ! str_contains($rawFrom, ':')) {
            $from = $from->startOfDay();
        }
        if (! str_contains($rawTo, 'T') && ! str_contains($rawTo, ':')) {
            $to = $to->endOfDay();
        }

        if ($from->gt($to)) {
            return response()->json([
                'message' => 'from must be on or before to.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Commission ledger summary',
            'data' => $service->summary($from, $to) + ['timezone' => $tz],
        ]);
    }
}
