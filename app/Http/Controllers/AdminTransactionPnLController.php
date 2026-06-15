<?php

namespace App\Http\Controllers;

use App\Services\Platform\AdminTransactionPnLService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminTransactionPnLController extends Controller
{
    public function index(Request $request, AdminTransactionPnLService $service): JsonResponse
    {
        $admin = auth('admin')->user();
        if (! $admin || ! $admin->role?->is_admin) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        $view = strtolower((string) $request->query('view', AdminTransactionPnLService::VIEW_EVENTS));
        if (! in_array($view, [AdminTransactionPnLService::VIEW_EVENTS, AdminTransactionPnLService::VIEW_FUN], true)) {
            return response()->json([
                'message' => 'Invalid view. Use events or fun.',
            ], 422);
        }

        $sort = strtolower((string) $request->query('sort', 'revenue'));
        if (! in_array($sort, ['revenue', 'gmv', 'margin'], true)) {
            return response()->json([
                'message' => 'Invalid sort. Use revenue, gmv, or margin.',
            ], 422);
        }

        $month = $request->query('month');
        if ($month !== null && preg_match('/^\d{4}-\d{2}$/', (string) $month) !== 1) {
            return response()->json([
                'message' => 'Invalid month format. Use YYYY-MM.',
            ], 422);
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(1, min(100, (int) $request->query('per_page', 10)));

        $data = $service->build($view, $month ? (string) $month : null, $sort, $page, $perPage);

        return response()->json([
            'success' => true,
            'message' => 'Transaction P&L leaderboard',
            'data' => $data,
        ]);
    }
}

