<?php

namespace App\Http\Controllers;

use App\Services\TwStockTaiexIndexService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TwStockTaiexIndexController extends Controller
{
    public function index(): View
    {
        return view('tw-stock.taiex-index-kline');
    }

    public function data(Request $request, TwStockTaiexIndexService $service): JsonResponse
    {
        $interval = strtolower((string) $request->query('interval', '1m'));
        if (! array_key_exists($interval, TwStockTaiexIndexService::intervals())) {
            return response()->json([
                'message' => 'interval 必須是 1m、5m、15m 或 1d。',
            ], 422);
        }

        return response()
            ->json($service->snapshot($interval))
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }
}
