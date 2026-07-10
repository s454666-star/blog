<?php

namespace Tests\Feature;

use Tests\TestCase;

class GoogleLoginCrawlerTestCommandTest extends TestCase
{
    public function test_dry_run_prints_node_probe_command(): void
    {
        $exitCode = $this->artisan('crawler:google-login-test', [
            'url' => 'https://example.com',
            '--timeout' => 5,
            '--dry-run' => true,
        ])->run();

        $this->assertSame(0, $exitCode);
    }

    public function test_invalid_url_fails_before_launching_browser(): void
    {
        $exitCode = $this->artisan('crawler:google-login-test', [
            'url' => 'not-a-url',
            '--dry-run' => true,
        ])->run();

        $this->assertSame(1, $exitCode);
    }

    public function test_85sugarbaby_dry_run_prints_node_probe_command(): void
    {
        $exitCode = $this->artisan('crawler:85sugarbaby-test', [
            '--timeout' => 5,
            '--proxy-server' => 'socks5://127.0.0.1:10885',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('--cookie-state=')
            ->expectsOutputToContain('--proxy-server=socks5://127.0.0.1:10885')
            ->run();

        $this->assertSame(0, $exitCode);
    }
}
