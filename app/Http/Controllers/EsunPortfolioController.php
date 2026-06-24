<?php

namespace App\Http\Controllers;

use App\Services\EsunPortfolioService;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EsunPortfolioController extends Controller
{
    private const ACCESS_COOKIE = 'esun_portfolio_access';

    public function index(Request $request, EsunPortfolioService $service): Response
    {
        $shouldRefreshCookie = $this->authorizePortfolio($request);

        $response = response()->view('tw-stock.esun-portfolio', [
            'apiUrl' => route('tw-stock.esun-portfolio.data'),
            'token' => (string) $request->query('token', ''),
            'initialMarket' => $service->marketStatus(),
        ]);

        if ($shouldRefreshCookie) {
            $response->withCookie($this->accessCookie($request));
        }

        return $response;
    }

    public function data(Request $request, EsunPortfolioService $service): JsonResponse
    {
        $shouldRefreshCookie = $this->authorizePortfolio($request);

        $response = response()->json($service->snapshot($request->boolean('force')));
        if ($shouldRefreshCookie) {
            $response->withCookie($this->accessCookie($request));
        }

        return $response;
    }

    private function authorizePortfolio(Request $request): bool
    {
        $expected = (string) config('esun.dashboard_token', '');
        if ($expected === '') {
            throw new HttpException(503, 'E.SUN portfolio dashboard token is not configured.');
        }

        $provided = (string) ($request->bearerToken() ?: $request->query('token', ''));
        if ($provided !== '' && hash_equals($expected, $provided)) {
            return true;
        }

        $cookie = (string) $request->cookie(self::ACCESS_COOKIE, '');
        abort_unless($cookie !== '' && hash_equals($this->accessCookieValue(), $cookie), 403);

        return false;
    }

    private function accessCookie(Request $request): Cookie
    {
        return cookie(
            self::ACCESS_COOKIE,
            $this->accessCookieValue(),
            60 * 24 * 30,
            null,
            null,
            $request->isSecure(),
            true,
            false,
            'Strict',
        );
    }

    private function accessCookieValue(): string
    {
        return hash_hmac(
            'sha256',
            (string) config('esun.dashboard_token', ''),
            (string) config('app.key', ''),
        );
    }
}
