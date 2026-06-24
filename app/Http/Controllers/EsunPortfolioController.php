<?php

namespace App\Http\Controllers;

use App\Services\EsunPortfolioService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EsunPortfolioController extends Controller
{
    public function index(Request $request, EsunPortfolioService $service): View
    {
        $this->authorizePortfolio($request);

        return view('tw-stock.esun-portfolio', [
            'apiUrl' => route('tw-stock.esun-portfolio.data'),
            'token' => (string) $request->query('token', ''),
            'initialMarket' => $service->marketStatus(),
        ]);
    }

    public function data(Request $request, EsunPortfolioService $service): JsonResponse
    {
        $this->authorizePortfolio($request);

        return response()->json($service->snapshot($request->boolean('force')));
    }

    private function authorizePortfolio(Request $request): void
    {
        $expected = (string) config('esun.dashboard_token', '');
        if ($expected === '') {
            throw new HttpException(503, 'E.SUN portfolio dashboard token is not configured.');
        }

        $provided = (string) ($request->bearerToken() ?: $request->query('token', ''));
        abort_unless(hash_equals($expected, $provided), 403);
    }
}
