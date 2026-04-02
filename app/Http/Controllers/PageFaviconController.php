<?php

namespace App\Http\Controllers;

use App\Support\PageFaviconResolver;
use Illuminate\Http\Response;

class PageFaviconController extends Controller
{
    public function __construct(private readonly PageFaviconResolver $resolver)
    {
    }

    public function show(string $slug): Response
    {
        $spec = $this->resolver->resolveBySlug($slug);
        $svg = $this->resolver->renderSvg($spec);

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=604800',
        ]);
    }
}
