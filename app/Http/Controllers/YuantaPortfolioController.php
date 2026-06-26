<?php

namespace App\Http\Controllers;

use App\Services\TwStockRealtimeQuoteService;
use App\Services\YuantaPortfolioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class YuantaPortfolioController extends Controller
{
    private const ACCESS_COOKIE = 'yuanta_portfolio_access';

    public function index(Request $request, YuantaPortfolioService $service): Response
    {
        $this->authorizePortfolio($request);

        $response = response()->view('tw-stock.esun-portfolio', [
            'apiUrl' => route('tw-stock.yuanta-portfolio.data'),
            'quoteUrl' => route('tw-stock.yuanta-portfolio.quotes'),
            'token' => (string) $request->query('token', ''),
            'initialMarket' => $service->marketStatus(),
            'pageTitle' => '元大庫存即時看板',
            'brokerName' => '元大',
        ]);

        return $this->clearRememberedAccess($response);
    }

    public function quotes(
        Request $request,
        YuantaPortfolioService $service,
        TwStockRealtimeQuoteService $quotes,
    ): JsonResponse {
        $this->authorizePortfolio($request);
        $codes = preg_split('/[\s,]+/', (string) $request->query('codes', ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $response = response()->json([
            ...$quotes->quotes($codes),
            'market' => $service->marketStatus(),
        ]);

        return $this->clearRememberedAccess($response);
    }

    public function data(Request $request, YuantaPortfolioService $service): JsonResponse
    {
        $this->authorizePortfolio($request);

        $response = response()->json($service->snapshot($request->boolean('force')));

        return $this->clearRememberedAccess($response);
    }

    private function authorizePortfolio(Request $request): void
    {
        $expected = (string) config('yuanta.dashboard_token', '');
        if ($expected === '') {
            throw new HttpException(503, 'Yuanta portfolio dashboard token is not configured.');
        }

        $provided = (string) ($request->bearerToken() ?: $request->query('token', ''));
        abort_unless($provided !== '' && hash_equals($expected, $provided), 403);
    }

    /**
     * @template T of SymfonyResponse
     * @param T $response
     * @return T
     */
    private function clearRememberedAccess(SymfonyResponse $response): SymfonyResponse
    {
        $response->headers->clearCookie(self::ACCESS_COOKIE);

        return $response;
    }
}
