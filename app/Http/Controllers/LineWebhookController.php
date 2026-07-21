<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class LineWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        return $this->handleWithConfig(
            $request,
            (string) config('line.channel_secret', ''),
            'line/dashboard-notify-target-id.txt',
            'line/latest-webhook-target.json',
            'line/personal-notify-target-id.txt',
            'line/latest-personal-webhook-target.json',
        );
    }

    public function handleYuanta(Request $request): JsonResponse
    {
        return $this->handleWithConfig(
            $request,
            (string) config('line.yuanta_channel_secret', ''),
            'line/yuanta-dashboard-notify-target-id.txt',
            'line/yuanta-latest-webhook-target.json',
            'line/yuanta-personal-notify-target-id.txt',
            'line/yuanta-latest-personal-webhook-target.json',
        );
    }

    private function handleWithConfig(
        Request $request,
        string $channelSecret,
        string $targetPath,
        string $metadataPath,
        string $personalTargetPath,
        string $personalMetadataPath,
    ): JsonResponse
    {
        $body = $request->getContent();

        if (! $this->signatureIsValid($body, (string) $request->header('X-Line-Signature', ''), $channelSecret)) {
            return response()->json(['message' => 'invalid signature'], 403);
        }

        $payload = json_decode($body, true);
        if (! is_array($payload)) {
            return response()->json(['message' => 'invalid json'], 400);
        }

        $capturedTargets = [];
        foreach ($payload['events'] ?? [] as $event) {
            if (! is_array($event)) {
                continue;
            }

            $source = is_array($event['source'] ?? null) ? $event['source'] : [];
            $target = $this->targetFromSource($source);

            Log::info('line_webhook_event', [
                'event_type' => $event['type'] ?? null,
                'source_type' => $source['type'] ?? null,
                'target_id' => $target['id'] ?? null,
                'mode' => $event['mode'] ?? null,
            ]);

            if ($target === null) {
                continue;
            }

            $this->storeLatestTarget(
                $target,
                $event,
                $target['type'] === 'user' ? $personalTargetPath : $targetPath,
                $target['type'] === 'user' ? $personalMetadataPath : $metadataPath,
            );
            $capturedTargets[] = $target;
        }

        return response()->json([
            'status' => 'ok',
            'captured_targets' => $capturedTargets,
        ]);
    }

    private function signatureIsValid(string $body, string $signature, string $secret): bool
    {
        if ($secret === '' || $signature === '') {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $body, $secret, true));

        return hash_equals($expected, $signature);
    }

    /**
     * @param array<string, mixed> $source
     *
     * @return array{id: string, type: string}|null
     */
    private function targetFromSource(array $source): ?array
    {
        if (($source['type'] ?? null) === 'group' && is_string($source['groupId'] ?? null)) {
            return ['type' => 'group', 'id' => $source['groupId']];
        }

        if (($source['type'] ?? null) === 'room' && is_string($source['roomId'] ?? null)) {
            return ['type' => 'room', 'id' => $source['roomId']];
        }

        if (($source['type'] ?? null) === 'user' && is_string($source['userId'] ?? null)) {
            return ['type' => 'user', 'id' => $source['userId']];
        }

        return null;
    }

    /**
     * @param array{id: string, type: string} $target
     * @param array<string, mixed> $event
     */
    private function storeLatestTarget(array $target, array $event, string $targetPath, string $metadataPath): void
    {
        Storage::disk('local')->put($targetPath, $target['id'] . "\n");
        Storage::disk('local')->put($metadataPath, json_encode([
            'target_id' => $target['id'],
            'target_type' => $target['type'],
            'event_type' => $event['type'] ?? null,
            'event_timestamp' => $event['timestamp'] ?? null,
            'mode' => $event['mode'] ?? null,
            'captured_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }
}
