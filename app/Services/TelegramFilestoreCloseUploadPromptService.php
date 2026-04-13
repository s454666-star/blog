<?php

namespace App\Services;

use App\Models\TelegramFilestoreFile;
use App\Models\TelegramFilestoreSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramFilestoreCloseUploadPromptService
{
    private const CLOSE_UPLOAD_PROMPT_MESSAGE_CACHE_MINUTES = 180;
    private const FRESH_PROMPT_MESSAGE_AFTER_SECONDS = 60;
    private const MISSING_PROMPT_RESCUE_AFTER_SECONDS = 900;
    private const MISSING_PROMPT_RESCUE_LOCK_SECONDS = 30;

    public function __construct(
        private TelegramFilestoreBotProfileResolver $botProfileResolver
    ) {
    }

    public function shouldPreferFreshPromptMessage(TelegramFilestoreSession $session): bool
    {
        $messageId = $this->getCloseUploadPromptMessageId((int) $session->id);
        if ($messageId === null) {
            return false;
        }

        $promptCreatedAt = $this->getPromptMessageCreatedAt((int) $session->id);
        if ($promptCreatedAt === null) {
            return true;
        }

        return $promptCreatedAt->diffInSeconds(now()) >= self::FRESH_PROMPT_MESSAGE_AFTER_SECONDS;
    }

    public function shouldRescueMissingPrompt(TelegramFilestoreSession $session): bool
    {
        if ((string) ($session->status ?? '') !== 'uploading') {
            return false;
        }

        if (trim((string) ($session->source_token ?? '')) !== '') {
            return false;
        }

        if ($this->getCloseUploadPromptMessageId((int) $session->id) !== null) {
            return false;
        }

        $baseline = $this->toCarbon($session->close_upload_prompted_at)
            ?? $this->toCarbon($session->created_at);

        if ($baseline === null) {
            return false;
        }

        return $baseline->diffInSeconds(now()) >= self::MISSING_PROMPT_RESCUE_AFTER_SECONDS;
    }

    /**
     * @return array{action:string,message_id:int|null}|null
     */
    public function rescueMissingPromptIfNeeded(
        int $sessionId,
        int $chatId,
        string $botProfile = TelegramFilestoreBotProfileResolver::FILESTORE
    ): ?array {
        $session = TelegramFilestoreSession::query()->find($sessionId);
        if (!$session || !$this->shouldRescueMissingPrompt($session)) {
            return null;
        }

        $lockKey = 'filestore_close_upload_prompt_rescue_lock_' . $sessionId;
        $locked = Cache::add($lockKey, 1, now()->addSeconds(self::MISSING_PROMPT_RESCUE_LOCK_SECONDS));
        if (!$locked) {
            return null;
        }

        $session->close_upload_prompted_at = now();
        $session->save();

        $result = $this->sendOrRefreshPrompt($sessionId, $chatId, $botProfile, true);

        Log::info('telegram_filestore_close_prompt_rescued_sync', [
            'session_id' => $sessionId,
            'chat_id' => $chatId,
            'action' => $result['action'],
            'message_id' => $result['message_id'],
        ]);

        return $result;
    }

    /**
     * @return array{action:string,message_id:int|null}
     */
    public function sendOrRefreshPrompt(
        int $sessionId,
        int $chatId,
        string $botProfile = TelegramFilestoreBotProfileResolver::FILESTORE,
        bool $preferFreshMessage = false
    ): array {
        $counts = $this->countFilesByType($sessionId);
        $text = $this->buildCloseUploadPromptText($counts);
        $keyboard = $this->buildCloseUploadPromptKeyboard();

        $oldMessageId = $this->getCloseUploadPromptMessageId($sessionId);

        if ($oldMessageId !== null && !$preferFreshMessage) {
            $ok = $this->editMessageText($chatId, $oldMessageId, $text, $keyboard, $botProfile);
            if ($ok) {
                return [
                    'action' => 'edited',
                    'message_id' => $oldMessageId,
                ];
            }

            $this->forgetCloseUploadPromptMessageId($sessionId);
            $oldMessageId = null;
        }

        if ($oldMessageId !== null && $preferFreshMessage) {
            $this->deleteMessage($chatId, $oldMessageId, $botProfile);
            $this->forgetCloseUploadPromptMessageId($sessionId);
        }

        $sentMessageId = $this->sendMessageReturningMessageId($chatId, $text, $keyboard, $botProfile);
        if ($sentMessageId === null) {
            return [
                'action' => 'failed',
                'message_id' => null,
            ];
        }

        $this->rememberCloseUploadPromptMessageId($sessionId, $sentMessageId);

        return [
            'action' => $preferFreshMessage ? 'resent' : 'sent',
            'message_id' => $sentMessageId,
        ];
    }

    public function deletePromptIfExists(
        int $sessionId,
        int $chatId,
        string $botProfile = TelegramFilestoreBotProfileResolver::FILESTORE
    ): void {
        $promptMessageId = $this->getCloseUploadPromptMessageId($sessionId);
        if ($promptMessageId !== null) {
            $this->deleteMessage($chatId, $promptMessageId, $botProfile);
        }

        $this->forgetCloseUploadPromptMessageId($sessionId);
    }

    private function countFilesByType(int $sessionId): array
    {
        $rows = TelegramFilestoreFile::query()
            ->selectRaw('file_type, COUNT(*) as total')
            ->where('session_id', $sessionId)
            ->groupBy('file_type')
            ->get();

        $result = [
            'photo' => 0,
            'video' => 0,
            'document' => 0,
            'other' => 0,
        ];

        foreach ($rows as $row) {
            $type = (string) ($row->file_type ?? '');
            $total = (int) ($row->total ?? 0);

            if (array_key_exists($type, $result)) {
                $result[$type] = $total;
            }
        }

        return $result;
    }

    private function buildCloseUploadPromptText(array $counts): string
    {
        $lines = [];
        $video = (int) ($counts['video'] ?? 0);
        $photo = (int) ($counts['photo'] ?? 0);
        $doc = (int) ($counts['document'] ?? 0) + (int) ($counts['other'] ?? 0);

        if ($video > 0) {
            $lines[] = "影片：{$video}";
        }

        if ($photo > 0) {
            $lines[] = "圖片：{$photo}";
        }

        if ($doc > 0) {
            $lines[] = "檔案：{$doc}";
        }

        if ($lines !== []) {
            return implode('　', $lines) . "\n是否結束上傳？";
        }

        return "是否結束上傳？";
    }

    private function buildCloseUploadPromptKeyboard(): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '結束上傳', 'callback_data' => 'filestore_close_upload'],
                    ['text' => '繼續上傳', 'callback_data' => 'filestore_continue_upload'],
                ],
                [
                    ['text' => '取消本次上傳', 'callback_data' => 'filestore_cancel_upload'],
                ],
            ],
        ];
    }

    private function rememberCloseUploadPromptMessageId(int $sessionId, int $messageId): void
    {
        Cache::put(
            $this->getCloseUploadPromptMessageCacheKey($sessionId),
            $messageId,
            now()->addMinutes(self::CLOSE_UPLOAD_PROMPT_MESSAGE_CACHE_MINUTES)
        );

        Cache::put(
            $this->getCloseUploadPromptCreatedAtCacheKey($sessionId),
            now()->toIso8601String(),
            now()->addMinutes(self::CLOSE_UPLOAD_PROMPT_MESSAGE_CACHE_MINUTES)
        );
    }

    private function getCloseUploadPromptMessageId(int $sessionId): ?int
    {
        $value = Cache::get($this->getCloseUploadPromptMessageCacheKey($sessionId));
        if ($value === null) {
            return null;
        }

        return (int) $value;
    }

    private function forgetCloseUploadPromptMessageId(int $sessionId): void
    {
        Cache::forget($this->getCloseUploadPromptMessageCacheKey($sessionId));
        Cache::forget($this->getCloseUploadPromptCreatedAtCacheKey($sessionId));
    }

    private function getCloseUploadPromptMessageCacheKey(int $sessionId): string
    {
        return 'filestore_close_upload_prompt_message_id_' . $sessionId;
    }

    private function getCloseUploadPromptCreatedAtCacheKey(int $sessionId): string
    {
        return 'filestore_close_upload_prompt_created_at_' . $sessionId;
    }

    private function getPromptMessageCreatedAt(int $sessionId): ?Carbon
    {
        return $this->toCarbon(Cache::get($this->getCloseUploadPromptCreatedAtCacheKey($sessionId)));
    }

    private function sendMessageReturningMessageId(
        int $chatId,
        string $text,
        array $replyMarkup,
        string $botProfile
    ): ?int {
        $token = (string) ($this->botProfileResolver->resolve($botProfile)['token'] ?? '');
        if ($token === '') {
            return null;
        }

        try {
            $resp = Http::timeout(30)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
                'reply_markup' => $replyMarkup,
            ]);
        } catch (Throwable $e) {
            Log::error('telegram_filestore_close_prompt_send_exception', [
                'chat_id' => $chatId,
                'message' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return null;
        }

        if (!$resp->ok()) {
            Log::error('telegram_filestore_close_prompt_send_failed', [
                'chat_id' => $chatId,
                'status' => $resp->status(),
                'body' => $resp->body(),
            ]);

            return null;
        }

        $messageId = $resp->json('result.message_id');
        if ($messageId === null) {
            return null;
        }

        return (int) $messageId;
    }

    private function editMessageText(
        int $chatId,
        int $messageId,
        string $text,
        array $replyMarkup,
        string $botProfile
    ): bool {
        $token = (string) ($this->botProfileResolver->resolve($botProfile)['token'] ?? '');
        if ($token === '') {
            return false;
        }

        try {
            $resp = Http::timeout(30)->post("https://api.telegram.org/bot{$token}/editMessageText", [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $text,
                'reply_markup' => $replyMarkup,
            ]);
        } catch (Throwable $e) {
            Log::error('telegram_filestore_close_prompt_edit_exception', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'message' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return false;
        }

        if ($resp->ok()) {
            return true;
        }

        $description = strtolower((string) ($resp->json('description') ?? ''));
        if ($resp->status() === 400 && str_contains($description, 'message is not modified')) {
            return true;
        }

        Log::warning('telegram_filestore_close_prompt_edit_failed', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'status' => $resp->status(),
            'body' => $resp->body(),
        ]);

        return false;
    }

    private function deleteMessage(int $chatId, int $messageId, string $botProfile): bool
    {
        $token = (string) ($this->botProfileResolver->resolve($botProfile)['token'] ?? '');
        if ($token === '') {
            return false;
        }

        try {
            $resp = Http::timeout(30)->post("https://api.telegram.org/bot{$token}/deleteMessage", [
                'chat_id' => $chatId,
                'message_id' => $messageId,
            ]);
        } catch (Throwable $e) {
            Log::warning('telegram_filestore_close_prompt_delete_exception', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'message' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return false;
        }

        return $resp->ok();
    }

    private function toCarbon(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy();
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        try {
            return Carbon::parse($normalized);
        } catch (Throwable) {
            return null;
        }
    }
}
