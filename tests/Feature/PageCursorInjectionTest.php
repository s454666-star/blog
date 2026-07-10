<?php

namespace Tests\Feature;

use Tests\TestCase;

class PageCursorInjectionTest extends TestCase
{
    public function test_web_portal_injects_kittydog_cursor_assets(): void
    {
        $response = $this->get('/web');

        $response->assertOk();
        $response->assertSee('fancy-cursor.css', false);
        $response->assertSee('fancy-cursor.js', false);
        $response->assertSee('data-fancy-cursor=', false);
        $response->assertSee('data-fancy-cursor-page="web"', false);
    }

    public function test_command_runner_page_still_receives_custom_cursor_markup(): void
    {
        $response = $this->get('/command-runner');

        $response->assertOk();
        $response->assertSee('data-fancy-cursor="wand"', false);
        $response->assertSee('data-fancy-cursor-page="command-runner"', false);
    }

    public function test_non_html_responses_do_not_get_cursor_injection(): void
    {
        $response = $this->get('/page-favicons/home.svg');

        $response->assertOk();
        $response->assertDontSee('fancy-cursor.css', false);
        $response->assertDontSee('data-fancy-cursor=', false);
    }

    public function test_stock_subdomain_uses_native_cursor(): void
    {
        $response = $this->get('http://stock.mystar.monster/');

        $response->assertOk();
        $response->assertDontSee('fancy-cursor.css', false);
        $response->assertDontSee('fancy-cursor.js', false);
        $response->assertDontSee('data-fancy-cursor=', false);
    }

    public function test_explicitly_excluded_pages_keep_the_native_cursor(): void
    {
        foreach (['/folder-video-app', '/tw-stock/esun-portfolio', '/tw-stock/yuanta-portfolio'] as $path) {
            $response = $this->get($path);

            $response->assertDontSee('fancy-cursor.css', false);
            $response->assertDontSee('fancy-cursor.js', false);
            $response->assertDontSee('data-fancy-cursor=', false);
        }
    }

    public function test_kittydog_cursor_styles_reference_the_downloaded_cursor_set(): void
    {
        $css = file_get_contents(public_path('css/fancy-cursor.css'));
        $javascript = file_get_contents(public_path('js/fancy-cursor.js'));

        $this->assertIsString($css);
        $this->assertIsString($javascript);
        $this->assertStringContainsString('/cursors/kittydog-crystal/normal.cur', $css);
        $this->assertStringContainsString('/cursors/kittydog-crystal/link.cur', $css);
        $this->assertStringContainsString('/cursors/kittydog-crystal/text.cur', $css);
        $this->assertStringContainsString('/cursors/kittydog-crystal/unavailable.cur', $css);
        $this->assertStringContainsString('kittydog-cursor-enabled', $javascript);
        $this->assertStringNotContainsString('fancy-cursor-layer', $javascript);
    }
}
