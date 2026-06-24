<?php

namespace App\Http\Controllers;

use App\Services\EsunPortfolioService;
use App\Services\TwStockRealtimeQuoteService;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EsunPortfolioController extends Controller
{
    private const ACCESS_COOKIE = 'esun_portfolio_access';

    public function index(Request $request, EsunPortfolioService $service): Response
    {
        $this->authorizePortfolio($request);

        $response = response()->view('tw-stock.esun-portfolio', [
            'apiUrl' => route('tw-stock.esun-portfolio.data'),
            'quoteUrl' => route('tw-stock.esun-portfolio.quotes'),
            'token' => (string) $request->query('token', ''),
            'initialMarket' => $service->marketStatus(),
        ]);

        return $this->clearRememberedAccess($response);
    }

    public function quotes(
        Request $request,
        EsunPortfolioService $service,
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

    public function data(Request $request, EsunPortfolioService $service): JsonResponse
    {
        $this->authorizePortfolio($request);

        $response = response()->json($service->snapshot($request->boolean('force')));

        return $this->clearRememberedAccess($response);
    }

    private function authorizePortfolio(Request $request): void
    {
        $expected = (string) config('esun.dashboard_token', '');
        if ($expected === '') {
            throw new HttpException(503, 'E.SUN portfolio dashboard token is not configured.');
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
