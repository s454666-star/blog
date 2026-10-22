<?php

namespace Tests\Feature;

use Tests\TestCase;

class LocalOnlyRoutesTest extends TestCase
{
    public function test_command_runner_is_blocked_on_the_public_host(): void
    {
        $this->withServerVariables(['HTTP_HOST' => 'mystar.monster', 'REMOTE_ADDR' => '203.0.113.10'])
            ->get('https://mystar.monster/command-runner')
            ->assertForbidden();
    }

    public function test_command_runner_is_blocked_when_proxied_from_aws(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '10.147.18.34'])
            ->get('https://blog/command-runner')
            ->assertForbidden();
    }

    public function test_destructive_and_proxy_routes_are_blocked_on_the_public_host(): void
    {
        $public = ['HTTP_HOST' => 'mystar.monster', 'REMOTE_ADDR' => '203.0.113.10'];

        $this->withServerVariables($public)->post('https://mystar.monster/videos/duplicates/delete')->assertForbidden();
        $this->withServerVariables($public)->post('https://mystar.monster/fetch')->assertForbidden();
        $this->withServerVariables($public)->get('https://mystar.monster/data/a.mp4')->assertForbidden();
        $this->withServerVariables($public)->get('https://mystar.monster/proxy-image?url=https://example.com/a.jpg')->assertForbidden();
    }

    public function test_command_runner_is_allowed_on_the_local_site(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->get('https://blog/command-runner')
            ->assertOk();
    }
}
