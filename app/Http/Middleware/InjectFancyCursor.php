<?php

namespace App\Http\Middleware;

use App\Support\PageCursorResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InjectFancyCursor
{
    public function __construct(private readonly PageCursorResolver $resolver)
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
        if (!is_string($content) || stripos($content, '</head>') === false || stripos($content, '<body') === false) {
            return $response;
        }

        $contentType = strtolower((string) $response->headers->get('Content-Type', ''));
        if ($contentType !== '' && !str_contains($contentType, 'text/html')) {
            return $response;
        }

        if (str_contains($content, 'fancy-cursor.css') || str_contains($content, 'fancy-cursor.js') || str_contains($content, 'data-fancy-cursor=')) {
            return $response;
        }

        $spec = $this->resolver->resolveRequest($request);
        $variant = htmlspecialchars((string) $spec['variant'], ENT_QUOTES, 'UTF-8');
        $behavior = htmlspecialchars((string) $spec['behavior'], ENT_QUOTES, 'UTF-8');
        $slug = htmlspecialchars((string) $spec['slug'], ENT_QUOTES, 'UTF-8');

        $styleHref = htmlspecialchars($this->assetUrl('css/fancy-cursor.css'), ENT_QUOTES, 'UTF-8');
        $scriptHref = htmlspecialchars($this->assetUrl('js/fancy-cursor.js'), ENT_QUOTES, 'UTF-8');

        $headInjection = implode("\n", [
            '    <!-- Fancy cursor -->',
            "    <link rel=\"stylesheet\" href=\"{$styleHref}\">",
            "    <script src=\"{$scriptHref}\" defer></script>",
        ]);

        $updated = preg_replace('/<\/head>/i', $headInjection . "\n</head>", $content, 1);
        if (!is_string($updated)) {
            return $response;
        }

        $updated = preg_replace(
            '/<body\b([^>]*)>/i',
            "<body$1 data-fancy-cursor=\"{$variant}\" data-fancy-cursor-behavior=\"{$behavior}\" data-fancy-cursor-page=\"{$slug}\">",
            $updated,
            1
        );

        if (is_string($updated)) {
            $response->setContent($updated);
        }

        return $response;
    }

    private function assetUrl(string $path): string
    {
        $version = @filemtime(public_path($path));

        return asset($path) . '?v=' . ($version ?: '1');
    }
}
