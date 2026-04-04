<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class TelegramWebhookLogContext
{
    public static function fromUpdate(array $update, string $bot): array
    {
        $context = [
            'bot' => $bot,
            'update_id' => $update['update_id'] ?? null,
            'update_type' => self::resolveUpdateType($update),
            'received_at' => now()->toIso8601String(),
        ];

        $payload = self::resolvePrimaryPayload($update);
        if (!is_array($payload)) {
            return self::filterEmpty($context);
        }

        $eventTimestamp = self::resolveEventTimestamp($payload);
        $chat = self::resolveChat($payload);
        $from = self::resolveFrom($payload);
        $messageText = self::extractMessageText($payload);
        $captionText = trim((string) ($payload['caption'] ?? ''));

        $context = array_merge($context, [
            'telegram_timestamp' => $eventTimestamp,
            'telegram_at' => self::formatTimestamp($eventTimestamp),
            'chat_id' => $chat['id'] ?? null,
            'chat_type' => $chat['type'] ?? null,
            'chat_username' => $chat['username'] ?? null,
            'chat_title' => $chat['title'] ?? null,
            'chat_display_name' => self::buildDisplayName($chat),
            'user_id' => $from['id'] ?? null,
            'user_username' => $from['username'] ?? null,
            'user_first_name' => $from['first_name'] ?? null,
            'user_last_name' => $from['last_name'] ?? null,
            'user_display_name' => self::buildDisplayName($from),
            'language_code' => $from['language_code'] ?? null,
            'message_id' => $payload['message_id'] ?? null,
            'message_kind' => self::resolveMessageKind($payload),
            'message_text' => self::limitText($messageText),
            'message_text_length' => self::measureTextLength($messageText),
            'caption_text' => self::limitText($captionText),
            'caption_text_length' => self::measureTextLength($captionText),
            'callback_query_id' => $update['callback_query']['id'] ?? null,
            'callback_data' => self::limitText((string) data_get($update, 'callback_query.data', '')),
            'callback_message_id' => data_get($update, 'callback_query.message.message_id'),
            'my_chat_member_old_status' => data_get($update, 'my_chat_member.old_chat_member.status'),
            'my_chat_member_new_status' => data_get($update, 'my_chat_member.new_chat_member.status'),
            'document_file_name' => data_get($payload, 'document.file_name'),
            'document_mime_type' => data_get($payload, 'document.mime_type'),
            'media_group_id' => $payload['media_group_id'] ?? null,
            'photo_count' => is_array($payload['photo'] ?? null) ? count($payload['photo']) : null,
            'has_document' => isset($payload['document']),
            'has_video' => isset($payload['video']),
            'has_photo' => isset($payload['photo']),
            'has_audio' => isset($payload['audio']),
            'has_voice' => isset($payload['voice']),
            'has_animation' => isset($payload['animation']),
            'has_sticker' => isset($payload['sticker']),
        ]);

        return self::filterEmpty($context);
    }

    private static function resolveUpdateType(array $update): string
    {
        foreach (['message', 'edited_message', 'callback_query', 'my_chat_member'] as $type) {
            if (isset($update[$type])) {
                return $type;
            }
        }

        return 'unknown';
    }

    private static function resolvePrimaryPayload(array $update): ?array
    {
        foreach (['message', 'edited_message', 'callback_query', 'my_chat_member'] as $type) {
            if (isset($update[$type]) && is_array($update[$type])) {
                return $update[$type];
            }
        }

        return null;
    }

    private static function resolveEventTimestamp(array $payload): ?int
    {
        $timestamp = $payload['date'] ?? data_get($payload, 'message.date');
        if ($timestamp === null) {
            return null;
        }

        return (int) $timestamp;
    }

    private static function formatTimestamp(?int $timestamp): ?string
    {
        if ($timestamp === null || $timestamp <= 0) {
            return null;
        }

        return Carbon::createFromTimestamp($timestamp)->setTimezone(config('app.timezone', 'UTC'))->toIso8601String();
    }

    private static function resolveChat(array $payload): array
    {
        if (isset($payload['chat']) && is_array($payload['chat'])) {
            return $payload['chat'];
        }

        if (isset($payload['message']['chat']) && is_array($payload['message']['chat'])) {
            return $payload['message']['chat'];
        }

        return [];
    }

    private static function resolveFrom(array $payload): array
    {
        if (isset($payload['from']) && is_array($payload['from'])) {
            return $payload['from'];
        }

        if (isset($payload['message']['from']) && is_array($payload['message']['from'])) {
            return $payload['message']['from'];
        }

        if (isset($payload['new_chat_member']['user']) && is_array($payload['new_chat_member']['user'])) {
            return $payload['new_chat_member']['user'];
        }

        return [];
    }

    private static function extractMessageText(array $payload): string
    {
        if (isset($payload['text'])) {
            return trim((string) $payload['text']);
        }

        if (isset($payload['message']['text'])) {
            return trim((string) $payload['message']['text']);
        }

        return '';
    }

    private static function resolveMessageKind(array $payload): ?string
    {
        foreach (['text', 'photo', 'video', 'document', 'audio', 'voice', 'animation', 'sticker'] as $kind) {
            if (isset($payload[$kind])) {
                return $kind;
            }
        }

        if (isset($payload['message']['text'])) {
            return 'callback_message';
        }

        return null;
    }

    private static function buildDisplayName(array $entity): ?string
    {
        $parts = array_values(array_filter([
            trim((string) ($entity['first_name'] ?? '')),
            trim((string) ($entity['last_name'] ?? '')),
        ], static fn ($value): bool => $value !== ''));

        if (!empty($parts)) {
            return implode(' ', $parts);
        }

        $title = trim((string) ($entity['title'] ?? ''));
        if ($title !== '') {
            return $title;
        }

        $username = trim((string) ($entity['username'] ?? ''));
        if ($username !== '') {
            return $username;
        }

        return null;
    }

    private static function limitText(string $text): ?string
    {
        $text = self::normalizeText($text);
        if ($text === '') {
            return null;
        }

        return Str::limit($text, 1200, '...[truncated]');
    }

    private static function normalizeText(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        return preg_replace('/\s+/u', ' ', $text) ?? $text;
    }

    private static function measureTextLength(string $text): ?int
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        return mb_strlen($text, 'UTF-8');
    }

    private static function filterEmpty(array $context): array
    {
        return array_filter($context, static function ($value): bool {
            return $value !== null && $value !== '' && $value !== [] && $value !== false;
        });
    }
}
