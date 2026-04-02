<?php

namespace App\Http\Middleware;

use App\Support\PageFaviconResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InjectPageFavicon
{
    public function __construct(private readonly PageFaviconResolver $resolver)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if ($response instanceof BinaryFileResponse || $response instanceof StreamedResponse) {
            return $response;
        }

        $content = $response->getContent();
        if (!is_string($content) || stripos($content, '</head>') === false) {
            return $response;
        }

        $contentType = strtolower((string) $response->headers->get('Content-Type', ''));
        if ($contentType !== '' && !str_contains($contentType, 'text/html')) {
            return $response;
        }

        if (preg_match('/rel=["\'](?:shortcut\\s+)?icon["\']/i', $content)) {
            return $response;
        }

        $spec = $this->resolver->resolveRequest($request);
        $iconHref = route('page-favicon', ['slug' => $spec['slug']]);
        $iconHref = htmlspecialchars($iconHref, ENT_QUOTES, 'UTF-8');
        $theme = htmlspecialchars((string) $spec['theme'], ENT_QUOTES, 'UTF-8');

        $injection = implode("\n", [
            '    <!-- Dynamic page favicon -->',
            "    <link rel=\"icon\" type=\"image/svg+xml\" href=\"{$iconHref}\">",
            "    <link rel=\"alternate icon\" href=\"{$iconHref}\">",
            "    <meta name=\"theme-color\" content=\"{$theme}\">",
        ]);

        $updated = preg_replace('/<\/head>/i', $injection . "\n</head>", $content, 1);
        if (is_string($updated)) {
            $response->setContent($updated);
        }

        return $response;
    }
}
