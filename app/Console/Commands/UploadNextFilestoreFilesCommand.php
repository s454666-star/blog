<?php

namespace App\Console\Commands;

use App\Models\TelegramFilestoreFile;
use App\Models\TelegramFilestoreSession;
use App\Services\TelegramFilestoreStaleSessionCleanupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

class UploadNextFilestoreFilesCommand extends Command
{
    private const DEFAULT_CHAT_ID = 7702694790;
    private const DEFAULT_SOURCE_DIR = 'Z:\\video(重跑)';
    private const DEFAULT_TDL_PATH = 'C:\\Users\\User\\Videos\\Captures\\tdl.exe';
    private const DEFAULT_FASTAPI_BASE_URI = 'http://127.0.0.1:8001/';
    private const DEFAULT_FASTAPI_FALLBACK_BASE_URI = 'http://127.0.0.1:8002/';
    private const DEFAULT_BOT_USERNAME = 'filestoebot';
    private const POLL_INTERVAL_MICROSECONDS = 1500000;

    protected $signature = 'filestore:upload-next
        {--limit=100 : 本次最多上傳幾筆}
        {--method=tdl : 上傳方法：tdl 或 api-video}
        {--chat-id=7702694790 : 寫入 filestore 的 Telegram chat_id}
        {--source=Z:\\video(重跑) : 來源資料夾}
        {--bot=filestoebot : Telegram bot username（可帶或不帶 @）}
        {--base-uri=http://127.0.0.1:8001/ : 本機 Telegram FastAPI base uri}
        {--fallback-base-uri=http://127.0.0.1:8002/ : api-video 失敗時改打的備援 FastAPI base uri}
        {--api-timeout-seconds=120 : api-video 每次 HTTP 請求 timeout 秒數}
        {--tdl=C:\\Users\\User\\Videos\\Captures\\tdl.exe : tdl.exe 路徑}
        {--tdl-storage= : 傳給 tdl 的 --storage 規格，留空用目前預設已登入 storage}
        {--tdl-namespace=default : 傳給 tdl 的 namespace}
        {--wait-seconds=90 : 每筆上傳後等待 webhook 寫入 DB 的秒數}
        {--sleep-ms=1200 : 每筆上傳之間暫停毫秒數}
        {--dry-run : 只列出將上傳的檔案，不真的送出}';

    protected $description = '從 telegram_filestore 最新已上傳檔名續跑，依來源資料夾修改日期排序後，用 tdl 或本機 Telegram FastAPI(api-video) 上傳下一批檔案到 @filestoebot';

    public function __construct(
        private TelegramFilestoreStaleSessionCleanupService $staleSessionCleanupService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $chatId = max(1, (int) ($this->option('chat-id') ?: self::DEFAULT_CHAT_ID));
        $limit = max(1, (int) $this->option('limit'));
        $method = strtolower(trim((string) $this->option('method')));
        $sourceDir = trim((string) ($this->option('source') ?: self::DEFAULT_SOURCE_DIR));
        $baseUri = rtrim(trim((string) ($this->option('base-uri') ?: self::DEFAULT_FASTAPI_BASE_URI)), '/') . '/';
        $fallbackBaseUri = rtrim(trim((string) ($this->option('fallback-base-uri') ?: self::DEFAULT_FASTAPI_FALLBACK_BASE_URI)), '/') . '/';
        $apiTimeoutSeconds = max(10, (int) $this->option('api-timeout-seconds'));
        $tdlPath = trim((string) ($this->option('tdl') ?: self::DEFAULT_TDL_PATH));
        $tdlStorage = trim((string) $this->option('tdl-storage'));
        $tdlNamespace = trim((string) $this->option('tdl-namespace'));
        $botUsername = ltrim(trim((string) ($this->option('bot') ?: self::DEFAULT_BOT_USERNAME)), '@');
        $waitSeconds = max(5, (int) $this->option('wait-seconds'));
        $sleepMs = max(0, (int) $this->option('sleep-ms'));
        $dryRun = (bool) $this->option('dry-run');

        if ($chatId <= 0) {
            $this->error('chat-id 必須大於 0。');
            return self::FAILURE;
        }

        if ($sourceDir === '' || !File::isDirectory($sourceDir)) {
            $this->error("來源資料夾不存在：{$sourceDir}");
            return self::FAILURE;
        }

        if (!in_array($method, ['tdl', 'api-video'], true)) {
            $this->error("不支援的 method：{$method}，只支援 tdl 或 api-video。");
            return self::FAILURE;
        }

        if ($botUsername === '') {
            $this->error('bot username 不可為空。');
            return self::FAILURE;
        }

        if ($method === 'tdl') {
            if ($tdlPath === '' || !File::exists($tdlPath)) {
                $this->error("找不到 tdl.exe：{$tdlPath}");
                return self::FAILURE;
            }
        }

        if ($method === 'api-video' && $baseUri === '/') {
            $this->error('base-uri 不可為空。');
            return self::FAILURE;
        }

        $this->staleSessionCleanupService->cleanupStaleUploadingSessions(chatId: $chatId);

        $latestSession = TelegramFilestoreSession::query()
            ->where('chat_id', $chatId)
            ->orderByDesc('id')
            ->first();

        $anchorFile = $this->resolveAnchorFile($chatId, $latestSession);
        $sourceFiles = $this->loadSourceFiles($sourceDir);
        if ($sourceFiles === []) {
            $this->warn('來源資料夾沒有可上傳檔案。');
            return self::SUCCESS;
        }

        try {
            $batch = $this->pickNextBatch($sourceFiles, $anchorFile['file_name'] ?? null, $limit);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
        if ($batch === []) {
            $anchorText = (string) ($anchorFile['file_name'] ?? '(無)');
            $this->info("沒有比 anchor 更後面的檔案可上傳。anchor={$anchorText}");
            return self::SUCCESS;
        }

        $this->line('chat_id=' . $chatId);
        $this->line('source=' . $sourceDir);
        $this->line('bot=@' . $botUsername);
        $this->line('method=' . $method);
        if ($method === 'api-video') {
            $this->line('base_uri=' . $baseUri);
            if ($fallbackBaseUri !== '/') {
                $this->line('fallback_base_uri=' . $fallbackBaseUri);
            }
        } else {
            $this->line('tdl=' . $tdlPath);
        }
        $this->line('latest_session_id=' . (string) ($latestSession?->id ?? 'null'));
        $this->line('latest_session_status=' . (string) ($latestSession?->status ?? 'null'));
        $this->line('anchor=' . (string) ($anchorFile['file_name'] ?? '(最前面開始)'));
        $this->line('batch_count=' . count($batch));

        foreach ($batch as $index => $file) {
            $this->line(sprintf(
                '[%d/%d] %s | mtime=%s | size=%d',
                $index + 1,
                count($batch),
                $file['name'],
                date('Y-m-d H:i:s', $file['mtime']),
                $file['size']
            ));
        }

        if ($dryRun) {
            $this->info('dry-run 結束，未實際上傳。');
            return self::SUCCESS;
        }

        $baselineSessionId = (int) ($latestSession?->id ?? 0);
        $targetSessionId = $latestSession && (string) $latestSession->status === 'uploading'
            ? (int) $latestSession->id
            : null;

        $verifiedRows = [];

        foreach ($batch as $index => $file) {
            $beforeMaxFileId = 0;
            $beforeCount = 0;

            if ($targetSessionId !== null) {
                $beforeMaxFileId = (int) (TelegramFilestoreFile::query()
                    ->where('session_id', $targetSessionId)
                    ->max('id') ?? 0);
                $beforeCount = (int) (TelegramFilestoreFile::query()
                    ->where('session_id', $targetSessionId)
                    ->count());
            }

            $this->newLine();
            $this->info(sprintf(
                '開始上傳 [%d/%d] %s',
                $index + 1,
                count($batch),
                $file['name']
            ));

            if ($method === 'api-video') {
                try {
                    $apiResult = $this->uploadVideoViaFastApiWithFallback(
                        $baseUri,
                        $fallbackBaseUri,
                        $botUsername,
                        $file['path'],
                        $apiTimeoutSeconds
                    );
                } catch (\RuntimeException $e) {
                    $this->error($e->getMessage());
                    return self::FAILURE;
                }

                $this->line(sprintf(
                    'api used_base_uri=%s sent_message_id=%d sent_as_video=%s',
                    (string) ($apiResult['used_base_uri'] ?? $baseUri),
                    (int) ($apiResult['sent_message_id'] ?? 0),
                    !empty($apiResult['sent_as_video']) ? 'true' : 'false'
                ));
            } else {
                $process = $this->makeTdlProcess(
                    $tdlPath,
                    $tdlNamespace,
                    $tdlStorage,
                    $botUsername,
                    $file['path']
                );
                $process->setTimeout(null);
                $process->setIdleTimeout(null);

                $exitCode = $process->run(function (string $type, string $buffer): void {
                    $this->streamTdlOutput($type, $buffer);
                });

                if ($exitCode !== 0) {
                    $this->error('tdl upload 失敗，停止續傳。');
                    return self::FAILURE;
                }
            }

            $verified = $this->waitForUploadedRow(
                $chatId,
                $targetSessionId,
                $baselineSessionId,
                $beforeMaxFileId,
                $beforeCount,
                $file['name'],
                $file['size'],
                $waitSeconds
            );

            if ($verified === null) {
                $this->error("等待 webhook 寫入逾時：{$file['name']}");
                return self::FAILURE;
            }

            $targetSessionId = (int) $verified['session_id'];
            $verifiedRows[] = $verified;

            if ($method === 'api-video' && (string) ($verified['file_type'] ?? '') !== 'video') {
                $this->error(sprintf(
                    'Webhook 已寫入，但 file_type=%s，不是 video：%s',
                    (string) ($verified['file_type'] ?? 'unknown'),
                    $file['name']
                ));
                return self::FAILURE;
            }

            $this->info(sprintf(
                '已驗證 session_id=%d file_id=%d file_name=%s file_type=%s',
                $verified['session_id'],
                $verified['id'],
                (string) ($verified['file_name'] ?? '(null)'),
                (string) ($verified['file_type'] ?? 'unknown')
            ));

            if ($sleepMs > 0 && $index < count($batch) - 1) {
                usleep($sleepMs * 1000);
            }
        }

        $this->newLine();
        $this->info('全部上傳完成。');
        if ($targetSessionId !== null) {
            $this->line('target_session_id=' . $targetSessionId);
        }

        foreach ($verifiedRows as $row) {
            $this->line(sprintf(
                'verified file_id=%d session_id=%d file_name=%s size=%d',
                $row['id'],
                $row['session_id'],
                (string) ($row['file_name'] ?? '(null)'),
                (int) ($row['file_size'] ?? 0)
            ));
        }

        return self::SUCCESS;
    }

    private function resolveAnchorFile(int $chatId, ?TelegramFilestoreSession $latestSession): ?array
    {
        $latestFile = null;

        if ($latestSession !== null) {
            $latestFile = TelegramFilestoreFile::query()
                ->where('session_id', $latestSession->id)
                ->orderByDesc('id')
                ->first();
        }

        if ($latestFile === null) {
            $latestFile = TelegramFilestoreFile::query()
                ->where('chat_id', $chatId)
                ->orderByDesc('id')
                ->first();
        }

        if ($latestFile === null) {
            return null;
        }

        return [
            'id' => (int) $latestFile->id,
            'file_name' => $latestFile->file_name,
            'session_id' => (int) $latestFile->session_id,
        ];
    }

    /**
     * @return array<int, array{name:string,path:string,size:int,mtime:int}>
     */
    private function loadSourceFiles(string $sourceDir): array
    {
        $files = [];

        foreach (File::files($sourceDir) as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $files[] = [
                'name' => $file->getFilename(),
                'path' => $file->getPathname(),
                'size' => (int) $file->getSize(),
                'mtime' => (int) $file->getMTime(),
            ];
        }

        usort($files, function (array $left, array $right): int {
            if ($left['mtime'] !== $right['mtime']) {
                return $left['mtime'] <=> $right['mtime'];
            }

            return strnatcasecmp($left['name'], $right['name']);
        });

        return $files;
    }

    /**
     * @param array<int, array{name:string,path:string,size:int,mtime:int}> $sourceFiles
     * @return array<int, array{name:string,path:string,size:int,mtime:int}>
     */
    private function pickNextBatch(array $sourceFiles, ?string $anchorFileName, int $limit): array
    {
        if ($anchorFileName === null || trim($anchorFileName) === '') {
            return array_slice($sourceFiles, 0, $limit);
        }

        $anchorIndex = null;

        foreach ($sourceFiles as $index => $file) {
            if ($file['name'] === $anchorFileName) {
                $anchorIndex = $index;
            }
        }

        if ($anchorIndex === null) {
            throw new \RuntimeException("找不到 anchor 檔案：{$anchorFileName}");
        }

        return array_slice($sourceFiles, $anchorIndex + 1, $limit);
    }

    private function makeTdlProcess(
        string $tdlPath,
        string $tdlNamespace,
        string $tdlStorage,
        string $botUsername,
        string $filePath
    ): Process {
        $command = [$tdlPath];

        if ($tdlNamespace !== '') {
            $command[] = '--ns';
            $command[] = $tdlNamespace;
        }

        if ($tdlStorage !== '') {
            $command[] = '--storage';
            $command[] = $tdlStorage;
        }

        $command[] = 'upload';
        $command[] = '--chat';
        $command[] = $botUsername;
        $command[] = '--path';
        $command[] = $filePath;

        return new Process($command, base_path());
    }

    private function streamTdlOutput(string $type, string $buffer): void
    {
        $text = preg_replace('/\e\[[\d;?]*[A-Za-z]/', '', $buffer) ?? $buffer;
        $text = str_replace("\r", "\n", $text);
        $prefix = $type === Process::ERR ? 'tdl[err]' : 'tdl';

        foreach (preg_split("/\n+/", $text) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if ($this->shouldSkipTdlLine($line)) {
                continue;
            }

            $this->line($prefix . ': ' . $line);
        }
    }

    private function shouldSkipTdlLine(string $line): bool
    {
        if (str_starts_with($line, 'CPU:')) {
            return true;
        }

        if (preg_match('/^\[[#.\s]+\]/', $line) === 1) {
            return true;
        }

        if (str_contains($line, ' -> ') && str_contains($line, '%') && !str_contains($line, 'done!')) {
            return true;
        }

        return false;
    }

    private function uploadVideoViaFastApi(string $baseUri, string $botUsername, string $filePath, int $timeoutSeconds): array
    {
        try {
            $response = Http::baseUrl($baseUri)
                ->connectTimeout(10)
                ->timeout($timeoutSeconds)
                ->acceptJson()
                ->post('bots/upload-video', [
                    'bot_username' => $botUsername,
                    'file_path' => $filePath,
                    'supports_streaming' => true,
                ]);
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf(
                'FastAPI upload-video 連線失敗：base_uri=%s error=%s',
                $baseUri,
                $e->getMessage()
            ));
        }

        if (!$response->successful()) {
            throw new \RuntimeException(sprintf(
                'FastAPI upload-video 失敗：HTTP %d body=%s',
                $response->status(),
                $this->truncateText($response->body())
            ));
        }

        $json = $response->json();
        if (!is_array($json)) {
            throw new \RuntimeException('FastAPI upload-video 回應不是 JSON 物件。');
        }

        if ((string) ($json['status'] ?? '') !== 'ok') {
            throw new \RuntimeException(sprintf(
                'FastAPI upload-video 回傳錯誤：reason=%s error=%s',
                (string) ($json['reason'] ?? 'unknown'),
                (string) ($json['error'] ?? '')
            ));
        }

        return $json;
    }

    private function uploadVideoViaFastApiWithFallback(
        string $baseUri,
        string $fallbackBaseUri,
        string $botUsername,
        string $filePath,
        int $timeoutSeconds
    ): array {
        $uris = [rtrim($baseUri, '/') . '/'];
        $normalizedFallback = rtrim($fallbackBaseUri, '/') . '/';
        if ($normalizedFallback !== '/' && !in_array($normalizedFallback, $uris, true)) {
            $uris[] = $normalizedFallback;
        }

        $errors = [];

        foreach ($uris as $uri) {
            try {
                $result = $this->uploadVideoViaFastApi($uri, $botUsername, $filePath, $timeoutSeconds);
                $result['used_base_uri'] = $uri;
                return $result;
            } catch (\RuntimeException $e) {
                $errors[] = $uri . ' => ' . $e->getMessage();
                $this->warn('api-video 失敗，改試下一個 base uri：' . $uri);
            }
        }

        throw new \RuntimeException('api-video 全部失敗：' . implode(' | ', $errors));
    }

    private function truncateText(string $text, int $limit = 800): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        if (strlen($text) <= $limit) {
            return $text;
        }

        return substr($text, 0, $limit) . '...';
    }

    private function waitForUploadedRow(
        int $chatId,
        ?int $targetSessionId,
        int $baselineSessionId,
        int $beforeMaxFileId,
        int $beforeCount,
        string $expectedFileName,
        int $expectedFileSize,
        int $waitSeconds
    ): ?array {
        $deadline = microtime(true) + $waitSeconds;

        while (microtime(true) <= $deadline) {
            if ($targetSessionId === null) {
                $newSession = TelegramFilestoreSession::query()
                    ->where('chat_id', $chatId)
                    ->where('id', '>', $baselineSessionId)
                    ->orderByDesc('id')
                    ->first();

                if ($newSession !== null) {
                    $targetSessionId = (int) $newSession->id;
                    $beforeMaxFileId = 0;
                    $beforeCount = 0;
                }
            }

            if ($targetSessionId !== null) {
                $newRows = TelegramFilestoreFile::query()
                    ->where('session_id', $targetSessionId)
                    ->where('id', '>', $beforeMaxFileId)
                    ->orderBy('id')
                    ->get();

                if ($newRows->count() > 0) {
                    foreach ($newRows as $row) {
                        $rowFileName = (string) ($row->file_name ?? '');
                        $rowFileSize = (int) ($row->file_size ?? 0);

                        if ($rowFileName === $expectedFileName || $rowFileSize === $expectedFileSize) {
                            return [
                                'id' => (int) $row->id,
                                'session_id' => (int) $row->session_id,
                                'file_name' => $row->file_name,
                                'file_size' => $rowFileSize,
                                'file_type' => $row->file_type,
                            ];
                        }
                    }

                    $currentCount = (int) TelegramFilestoreFile::query()
                        ->where('session_id', $targetSessionId)
                        ->count();

                    if ($currentCount > $beforeCount) {
                        $row = TelegramFilestoreFile::query()
                            ->where('session_id', $targetSessionId)
                            ->orderByDesc('id')
                            ->first();
                        if ($row !== null) {
                            return [
                                'id' => (int) $row->id,
                                'session_id' => (int) $row->session_id,
                                'file_name' => $row->file_name,
                                'file_size' => (int) ($row->file_size ?? 0),
                                'file_type' => $row->file_type,
                            ];
                        }
                    }
                }
            }

            usleep(self::POLL_INTERVAL_MICROSECONDS);
        }

        return null;
    }
}
