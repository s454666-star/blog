<?php

namespace App\Services;

use App\Models\MzksrBotChat;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class MzksrBotChatRecorder
{
    public const BOT_USERNAME = 'mzksr_bot';

    public function recordFromUpdate(array $update): ?MzksrBotChat
    {
        $payload = $this->extractMessagePayload($update);
        if ($payload === null) {
            return null;
        }

        $chat = $payload['chat'];
        $chatId = (int) ($chat['id'] ?? 0);
        if ($chatId === 0) {
            return null;
        }

        $message = $payload['message'];
        $observedAt = isset($message['date']) && is_numeric($message['date'])
            ? Carbon::createFromTimestamp((int) $message['date'])
            : now();

        return $this->recordChat($chatId, [
            'chat_type' => $chat['type'] ?? null,
            'username' => $chat['username'] ?? ($message['from']['username'] ?? null),
            'first_name' => $chat['first_name'] ?? ($message['from']['first_name'] ?? null),
            'last_name' => $chat['last_name'] ?? ($message['from']['last_name'] ?? null),
            'title' => $chat['title'] ?? null,
            'last_message_id' => isset($message['message_id']) ? (int) $message['message_id'] : null,
            'first_seen_at' => $observedAt,
            'last_seen_at' => $observedAt,
            'interaction_count' => 1,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function recordChat(int $chatId, array $attributes = []): MzksrBotChat
    {
        $chat = MzksrBotChat::query()->firstOrNew(['chat_id' => $chatId]);
        $existingLastSeenAt = $chat->last_seen_at;
        $candidateFirstSeenAt = $this->normalizeDate($attributes['first_seen_at'] ?? null);
        $candidateLastSeenAt = $this->normalizeDate($attributes['last_seen_at'] ?? null)
            ?? $candidateFirstSeenAt
            ?? now();
        $shouldRefreshLatestFields = $existingLastSeenAt === null
            || $candidateLastSeenAt->greaterThanOrEqualTo($existingLastSeenAt);

        if ($shouldRefreshLatestFields) {
            $chat->chat_type = $this->preferLatestValue($chat->chat_type, $attributes['chat_type'] ?? null);
            $chat->username = $this->preferLatestValue($chat->username, $attributes['username'] ?? null);
            $chat->first_name = $this->preferLatestValue($chat->first_name, $attributes['first_name'] ?? null);
            $chat->last_name = $this->preferLatestValue($chat->last_name, $attributes['last_name'] ?? null);
            $chat->title = $this->preferLatestValue($chat->title, $attributes['title'] ?? null);

            if (array_key_exists('last_message_id', $attributes) && $attributes['last_message_id'] !== null) {
                $chat->last_message_id = (int) $attributes['last_message_id'];
            }
        } else {
            $chat->chat_type = $this->preferExistingValue($chat->chat_type, $attributes['chat_type'] ?? null);
            $chat->username = $this->preferExistingValue($chat->username, $attributes['username'] ?? null);
            $chat->first_name = $this->preferExistingValue($chat->first_name, $attributes['first_name'] ?? null);
            $chat->last_name = $this->preferExistingValue($chat->last_name, $attributes['last_name'] ?? null);
            $chat->title = $this->preferExistingValue($chat->title, $attributes['title'] ?? null);
        }

        $chat->first_seen_at = $this->minDate($chat->first_seen_at, $candidateFirstSeenAt ?? $candidateLastSeenAt);
        $chat->last_seen_at = $this->maxDate($chat->last_seen_at, $candidateLastSeenAt);
        $chat->interaction_count = ((int) $chat->interaction_count) + max(1, (int) ($attributes['interaction_count'] ?? 1));
        $chat->save();

        return $chat;
    }

    /**
     * @return array{message: array<string, mixed>, chat: array<string, mixed>}|null
     */
    private function extractMessagePayload(array $update): ?array
    {
        foreach (['message', 'edited_message', 'channel_post', 'edited_channel_post'] as $key) {
            $message = $update[$key] ?? null;
            if (!is_array($message) || !is_array($message['chat'] ?? null)) {
                continue;
            }

            return [
                'message' => $message,
                'chat' => $message['chat'],
            ];
        }

        $callbackMessage = $update['callback_query']['message'] ?? null;
        if (is_array($callbackMessage) && is_array($callbackMessage['chat'] ?? null)) {
            return [
                'message' => $callbackMessage,
                'chat' => $callbackMessage['chat'],
            ];
        }

        return null;
    }

    private function preferLatestValue(?string $currentValue, mixed $candidateValue): ?string
    {
        $normalized = $this->normalizeString($candidateValue);
        return $normalized ?? $currentValue;
    }

    private function preferExistingValue(?string $currentValue, mixed $candidateValue): ?string
    {
        return $currentValue ?: $this->normalizeString($candidateValue);
    }

    private function normalizeString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeDate(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return Carbon::instance($value);
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_string($value) && trim($value) !== '') {
            return Carbon::parse($value);
        }

        return null;
    }

    private function minDate(?CarbonInterface $current, CarbonInterface $candidate): CarbonInterface
    {
        if ($current === null) {
            return $candidate;
        }

        return $candidate->lessThan($current) ? $candidate : $current;
    }

    private function maxDate(?CarbonInterface $current, CarbonInterface $candidate): CarbonInterface
    {
        if ($current === null) {
            return $candidate;
        }

        return $candidate->greaterThan($current) ? $candidate : $current;
    }
}
