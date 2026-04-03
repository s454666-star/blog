<?php

namespace Tests\Feature;

use Tests\TestCase;

class PageCursorInjectionTest extends TestCase
{
    public function test_home_page_injects_fancy_cursor_assets(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('fancy-cursor.css', false);
        $response->assertSee('fancy-cursor.js', false);
        $response->assertSee('data-fancy-cursor="balloon"', false);
        $response->assertSee('data-fancy-cursor-page="home"', false);
    }

    public function test_command_runner_page_uses_magic_wand_cursor_theme(): void
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
}
