<?php

namespace Tests\Feature;

use Tests\TestCase;

class NavigationHubTest extends TestCase
{
    public function test_navigation_hub_lists_aws_work_and_k8s_destinations(): void
    {
        $response = $this->get(route('home'));

        $response
            ->assertOk()
            ->assertSee('工作與服務')
            ->assertSee('主動式 ETF 操作日報')
            ->assertSee('日常工作系統')
            ->assertSee('Polar BE CI/CD')
            ->assertSee('每週 Email Request')
            ->assertSee('https://polar.worldvision.org.tw', false)
            ->assertSee('https://staging-polar-api.worldvision.org.tw', false)
            ->assertSee('https://alpha-polar-api-internal.worldvision.org.tw', false)
            ->assertSee('target="_blank"', false)
            ->assertSee('rel="noopener noreferrer"', false)
            ->assertDontSee('videos/external-duplicates');
    }
}
