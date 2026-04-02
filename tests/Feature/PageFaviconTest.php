<?php

namespace Tests\Feature;

use Tests\TestCase;

class PageFaviconTest extends TestCase
{
    public function test_home_page_injects_dynamic_favicon(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('/page-favicons/home.svg', false);
        $response->assertSee('rel="icon"', false);
    }

    public function test_svg_favicon_route_returns_svg(): void
    {
        $response = $this->get('/page-favicons/ig-grabber.svg');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/svg+xml; charset=UTF-8');
        $response->assertSee('<svg', false);
        $response->assertSee('IG', false);
    }
}
