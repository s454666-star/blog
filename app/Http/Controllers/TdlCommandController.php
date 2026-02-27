<?php

    namespace App\Http\Controllers;

    use Illuminate\Http\Request;

    class TdlCommandController extends Controller
    {
        private const DEFAULT_WORKDIR = 'C:\Users\User\Videos\Captures';

        public function index()
        {
            return view('tdl.index', [
                'inputJson' => '',
                'outputCmd' => '',
                'error' => '',
                'pairPerLine' => 2,
                'threads' => 12,
                'limit' => 12,
                'workdir' => self::DEFAULT_WORKDIR,
            ]);
        }

        public function generate(Request $request)
        {
            $inputJson = (string) $request->input('json_text', '');
            $pairPerLine = (int) $request->input('pair_per_line', 2);
            $threads = (int) $request->input('threads', 12);
            $limit = (int) $request->input('limit', 12);

            $workdir = (string) $request->input('workdir', self::DEFAULT_WORKDIR);
            $workdir = $this->normalizeWorkdir($workdir);

            if ($pairPerLine <= 0) {
                $pairPerLine = 2;
            }
            if ($threads <= 0) {
                $threads = 12;
            }
            if ($limit <= 0) {
                $limit = 12;
            }

            $outputCmd = '';
            $error = '';

            try {
                $data = json_decode($inputJson, true, 512, JSON_THROW_ON_ERROR);

                $peerId = $this->extractPeerId($data);
                if ($peerId === null) {
                    throw new \RuntimeException('找不到 peer_id，請確認 JSON 內有 peer_id 欄位。');
                }

                $messageIds = $this->extractMessageIds($data);

                if (count($messageIds) === 0) {
                    throw new \RuntimeException('找不到任何 Message 的 id（會自動略過 MessageService）。');
                }

                $outputCmd = $this->buildTdlCommand((string) $peerId, $messageIds, $pairPerLine, $threads, $limit, $workdir);
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }

            return view('tdl.index', [
                'inputJson' => $inputJson,
                'outputCmd' => $outputCmd,
                'error' => $error,
                'pairPerLine' => $pairPerLine,
                'threads' => $threads,
                'limit' => $limit,
                'workdir' => $workdir,
            ]);
        }

        private function normalizeWorkdir(string $workdir): string
        {
            $workdir = trim($workdir);

            if ($workdir === '') {
                return self::DEFAULT_WORKDIR;
            }

            $workdir = rtrim($workdir, "\\/");

            if ($workdir === '') {
                return self::DEFAULT_WORKDIR;
            }

            return $workdir;
        }

        private function extractPeerId(array $data): ?int
        {
            if (isset($data['peer_id']) && is_numeric($data['peer_id'])) {
                return (int) $data['peer_id'];
            }

            if (isset($data['items'][0]['peer_id']['channel_id']) && is_numeric($data['items'][0]['peer_id']['channel_id'])) {
                return (int) $data['items'][0]['peer_id']['channel_id'];
            }

            return null;
        }

        private function extractMessageIds(array $data): array
        {
            $ids = [];

            if (!isset($data['items']) || !is_array($data['items'])) {
                return $ids;
            }

            foreach ($data['items'] as $item) {
                if (!is_array($item)) {
                    continue;
                }

                if (!isset($item['_']) || $item['_'] !== 'Message') {
                    continue;
                }

                if (!isset($item['id']) || !is_numeric($item['id'])) {
                    continue;
                }

                $ids[] = (int) $item['id'];
            }

            return $ids;
        }

        private function buildTdlCommand(string $peerId, array $messageIds, int $pairPerLine, int $threads, int $limit, string $workdir): string
        {
            $urls = [];
            foreach ($messageIds as $id) {
                $urls[] = 'https://t.me/c/' . $peerId . '/' . $id;
            }

            $cmdLines = [];
            $cmdLines[] = 'cd /d "' . $workdir . '"';
            $cmdLines[] = '';
            $cmdLines[] = 'tdl dl ^';

            $currentLineParts = [];
            $currentCountInLine = 0;

            foreach ($urls as $url) {
                $currentLineParts[] = '-u ' . $url;
                $currentCountInLine++;

                if ($currentCountInLine >= $pairPerLine) {
                    $cmdLines[] = '  ' . implode(' ', $currentLineParts) . ' ^';
                    $currentLineParts = [];
                    $currentCountInLine = 0;
                }
            }

            if (count($currentLineParts) > 0) {
                $cmdLines[] = '  ' . implode(' ', $currentLineParts) . ' ^';
            }

            $cmdLines[] = '  -t ' . $threads . ' -l ' . $limit;

            return implode("\r\n", $cmdLines);
        }
    }
