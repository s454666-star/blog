<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LineWebhookControllerTest extends TestCase
{
    public function test_it_captures_group_id_from_signed_line_webhook(): void
    {
        Storage::fake('local');
        config()->set('line.channel_secret', 'test-secret');

        $payload = [
            'events' => [
                [
                    'type' => 'message',
                    'mode' => 'active',
                    'timestamp' => 1771925609000,
                    'source' => [
                        'type' => 'group',
                        'groupId' => 'C0123456789abcdef',
                        'userId' => 'U0123456789abcdef',
                    ],
                    'message' => [
                        'type' => 'text',
                        'id' => '123',
                        'text' => 'hello',
                    ],
                ],
            ],
        ];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $response = $this->withHeader('X-Line-Signature', $this->sign($body))
            ->postJson('/api/line/webhook', $payload);

        $response->assertOk()
            ->assertJsonPath('captured_targets.0.type', 'group')
            ->assertJsonPath('captured_targets.0.id', 'C0123456789abcdef');

        Storage::disk('local')->assertExists('line/dashboard-notify-target-id.txt');
        $this->assertSame("C0123456789abcdef\n", Storage::disk('local')->get('line/dashboard-notify-target-id.txt'));
    }

    public function test_it_rejects_invalid_signature(): void
    {
        Storage::fake('local');
        config()->set('line.channel_secret', 'test-secret');

        $response = $this->withHeader('X-Line-Signature', 'invalid')
            ->postJson('/api/line/webhook', ['events' => []]);

        $response->assertForbidden();
        Storage::disk('local')->assertMissing('line/dashboard-notify-target-id.txt');
    }

    public function test_it_captures_yuanta_group_id_from_signed_line_webhook(): void
    {
        Storage::fake('local');
        config()->set('line.channel_secret', 'test-secret');
        config()->set('line.yuanta_channel_secret', 'yuanta-secret');

        $payload = [
            'events' => [
                [
                    'type' => 'message',
                    'mode' => 'active',
                    'timestamp' => 1771925609000,
                    'source' => [
                        'type' => 'group',
                        'groupId' => 'Cyuanta1234567890',
                        'userId' => 'U0123456789abcdef',
                    ],
                    'message' => [
                        'type' => 'text',
                        'id' => '123',
                        'text' => 'hello yuanta',
                    ],
                ],
            ],
        ];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $response = $this->withHeader('X-Line-Signature', $this->sign($body, 'yuanta-secret'))
            ->postJson('/api/line/yuanta/webhook', $payload);

        $response->assertOk()
            ->assertJsonPath('captured_targets.0.type', 'group')
            ->assertJsonPath('captured_targets.0.id', 'Cyuanta1234567890');

        Storage::disk('local')->assertMissing('line/dashboard-notify-target-id.txt');
        Storage::disk('local')->assertExists('line/yuanta-dashboard-notify-target-id.txt');
        $this->assertSame("Cyuanta1234567890\n", Storage::disk('local')->get('line/yuanta-dashboard-notify-target-id.txt'));
    }

    public function test_yuanta_webhook_rejects_original_line_signature(): void
    {
        Storage::fake('local');
        config()->set('line.channel_secret', 'test-secret');
        config()->set('line.yuanta_channel_secret', 'yuanta-secret');

        $payload = ['events' => []];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $response = $this->withHeader('X-Line-Signature', $this->sign($body))
            ->postJson('/api/line/yuanta/webhook', $payload);

        $response->assertForbidden();
        Storage::disk('local')->assertMissing('line/yuanta-dashboard-notify-target-id.txt');
    }

    public function test_it_captures_yuanta_direct_user_without_overwriting_group_target(): void
    {
        Storage::fake('local');
        config()->set('line.yuanta_channel_secret', 'yuanta-secret');
        Storage::disk('local')->put('line/yuanta-dashboard-notify-target-id.txt', "Cexisting-group\n");

        $payload = [
            'events' => [[
                'type' => 'message',
                'mode' => 'active',
                'timestamp' => 1771925609000,
                'source' => [
                    'type' => 'user',
                    'userId' => 'Udirect-user',
                ],
                'message' => [
                    'type' => 'text',
                    'id' => '124',
                    'text' => 'capture me',
                ],
            ]],
        ];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->withHeader('X-Line-Signature', $this->sign($body, 'yuanta-secret'))
            ->postJson('/api/line/yuanta/webhook', $payload)
            ->assertOk()
            ->assertJsonPath('captured_targets.0.type', 'user')
            ->assertJsonPath('captured_targets.0.id', 'Udirect-user');

        $this->assertSame("Cexisting-group\n", Storage::disk('local')->get('line/yuanta-dashboard-notify-target-id.txt'));
        $this->assertSame("Udirect-user\n", Storage::disk('local')->get('line/yuanta-personal-notify-target-id.txt'));
    }

    private function sign(string $body, string $secret = 'test-secret'): string
    {
        return base64_encode(hash_hmac('sha256', $body, $secret, true));
    }
}
