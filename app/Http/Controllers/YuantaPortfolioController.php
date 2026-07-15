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
            'intradayUrl' => route('tw-stock.yuanta-portfolio.intraday'),
            'historyUrl' => route('tw-stock.yuanta-portfolio.history'),
            'historyDatesUrl' => route('tw-stock.yuanta-portfolio.history-dates'),
            'token' => (string) $request->query('token', ''),
            'initialMarket' => $service->marketStatus(),
            'pageTitle' => '元大庫存即時看板',
            'brokerName' => '元大',
            'calibrationSeconds' => max(60, (int) config('yuanta.minimum_query_seconds', 60)),
        ]);

        return $this->clearRememberedAccess($response);
    }

    public function historyDates(Request $request, YuantaPortfolioService $service): JsonResponse
    {
        $this->authorizePortfolio($request);

        $response = response()->json([
            'dates' => $service->dailySnapshotDates(),
        ]);

        return $this->clearRememberedAccess($response);
    }

    public function history(Request $request, YuantaPortfolioService $service): JsonResponse
    {
        $this->authorizePortfolio($request);

        $date = (string) $request->query('date', '');
        abort_if($date === '', 422, 'date is required.');

        try {
            $payload = $service->dailySnapshotPayload($date);
        } catch (\InvalidArgumentException) {
            abort(422, 'date is invalid.');
        }

        abort_if($payload === null, 404, 'history snapshot not found.');

        $response = response()->json($payload);

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

    public function intraday(Request $request, TwStockRealtimeQuoteService $quotes): JsonResponse
    {
        $this->authorizePortfolio($request);
        $codes = preg_split('/[\s,]+/', (string) $request->query('codes', ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return $this->clearRememberedAccess(response()->json($quotes->intradayPrices($codes)));
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
