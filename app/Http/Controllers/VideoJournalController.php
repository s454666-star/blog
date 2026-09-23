<?php

namespace App\Http\Controllers;

use App\Models\VideoJournalEntry;
use App\Services\VideoJournalContent;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

class VideoJournalController extends Controller
{
    public function browse(Request $request)
    {
        $data = $request->validate(['path' => 'nullable|string|max:4096', 'q' => 'nullable|string|max:200']);
        $path = trim($data['path'] ?? '', " \t\n\r\0\x0B\"");
        if ($path === '') {
            $roots = PHP_OS_FAMILY === 'Windows' ? array_map(fn ($drive) => $drive.':/', range('C', 'Z')) : ['/'];
            return response()->json(['path' => '', 'parent' => null, 'items' => array_values(array_map(
                fn ($root) => ['name' => $root, 'path' => $root, 'directory' => true],
                array_filter($roots, fn ($root) => is_dir($root))
            )), 'truncated' => false]);
        }
        $resolved = realpath($path);
        if ($resolved === false || !is_dir($resolved) || !is_readable($resolved)) {
            throw ValidationException::withMessages(['path' => '無法開啟此資料夾，請確認磁碟已連接且有存取權限。']);
        }
        $names = @scandir($resolved);
        if ($names === false) {
            throw ValidationException::withMessages(['path' => '無法讀取此資料夾。']);
        }
        $request->session()->put('video_journal.folders', array_slice(array_values(array_unique([$resolved, ...$request->session()->get('video_journal.folders', [])])), 0, 20));
        $items = [];
        $search = trim($data['q'] ?? '');
        foreach ($names as $name) {
            if (str_starts_with($name, '.')) continue;
            $full = rtrim($resolved, '/\\').DIRECTORY_SEPARATOR.$name;
            $directory = is_dir($full);
            if (!$directory && !in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['mp4', 'webm', 'ogv', 'mov', 'm4v'], true)) continue;
            if ($search !== '' && mb_stripos($name, $search) === false) continue;
            $items[] = ['name' => $name, 'path' => $full, 'directory' => $directory];
        }
        usort($items, fn ($a, $b) => ($b['directory'] <=> $a['directory']) ?: strnatcasecmp($a['name'], $b['name']));
        $parent = dirname($resolved);
        return response()->json(['path' => $resolved, 'parent' => $parent === $resolved ? '' : $parent, 'items' => array_slice($items, 0, 300), 'truncated' => count($items) > 300]);
    }

    public function index(Request $request)
    {
        $search = mb_substr(trim((string) $request->query('q', '')), 0, 200);
        $entries = VideoJournalEntry::query()->select(['id', 'title', 'tags', 'created_at', 'updated_at'])
            ->selectRaw('json_array_length(covers) AS cover_count')
            ->selectRaw('EXISTS (SELECT 1 FROM video_journal_faces WHERE entry_id = video_journal_entries.id) AS has_portrait')
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search) {
                $query->where('title', 'like', '%'.$search.'%')->orWhereRaw('EXISTS (SELECT 1 FROM json_each(video_journal_entries.tags) WHERE value LIKE ?)', ['%'.$search.'%']);
            }))
            ->orderByDesc('updated_at')->orderByDesc('id')->paginate(12)->withQueryString();
        return view('video-journal.index', ['entries' => $entries, 'search' => $search, 'total' => VideoJournalEntry::count()]);
    }

    public function resolveDrop(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:255', 'size' => 'required|integer|min:0', 'fingerprint' => 'required|string|regex:/^[a-f0-9]{64}$/', 'path' => 'nullable|string|max:4096', 'folder' => 'nullable|string|max:4096']);
        if (preg_match('~[/\\\\\x00]~', $data['name']) || !in_array(strtolower(pathinfo($data['name'], PATHINFO_EXTENSION)), ['mp4', 'webm', 'ogv', 'mov', 'm4v'], true)) {
            throw ValidationException::withMessages(['name' => '請拖入 MP4、WebM、OGV、MOV 或 M4V 影片。']);
        }
        $folders = $request->session()->get('video_journal.folders', []);
        foreach (VideoJournalEntry::query()->orderByDesc('updated_at')->limit(100)->pluck('source') as $source) {
            if (!preg_match('~^https?://~i', $source)) $folders[] = dirname($source);
        }
        if (!empty($data['folder'])) $folders = [$data['folder']];
        if (!empty($data['path'])) $folders = [];
        $candidates = empty($data['path']) ? [] : [$data['path']];
        foreach (array_unique($folders) as $folder) $candidates[] = rtrim($folder, '/\\').DIRECTORY_SEPARATOR.$data['name'];
        $matches = [];
        foreach (array_unique($candidates) as $path) {
            if (!preg_match('~^(?:[A-Za-z]:[\\\\/]|/|\\\\\\\\)~', $path)) continue;
            if (strcasecmp(basename(str_replace('\\', '/', $path)), $data['name']) !== 0) continue;
            $resolved = realpath($path);
            if ($resolved && is_file($resolved) && filesize($resolved) === (int) $data['size'] && hash_equals($data['fingerprint'], $this->sampleFingerprint($resolved, (int) $data['size']))) {
                $matches[strtolower($resolved)] = ['name' => basename(str_replace('\\', '/', $resolved)), 'path' => $resolved];
            }
        }
        return response()->json(['matches' => array_values($matches)]);
    }

    private function sampleFingerprint(string $path, int $size): string
    {
        $stream = @fopen($path, 'rb');
        if (!$stream) return '';
        $hash = hash_init('sha256');
        try {
            foreach ([0, max(0, (int) floor(($size - 65536) / 2)), max(0, $size - 65536)] as $offset) {
                if (fseek($stream, $offset) !== 0) return '';
                $chunk = fread($stream, 65536);
                if ($chunk === false) return '';
                hash_update($hash, $chunk);
            }
            return hash_final($hash);
        } finally { fclose($stream); }
    }

    public function store(Request $request)
    {
        $data = $request->validate(['title' => 'nullable|string|max:200', 'source' => 'required|string|max:4096']);
        $data['source'] = trim($data['source'], " \t\n\r\0\x0B\"");
        $source = $data['source'];
        $this->validateSource($source);
        $remote = preg_match('~^https?://~i', $source) === 1;
        $filename = basename(str_replace('\\', '/', $remote ? (parse_url($source, PHP_URL_PATH) ?: 'video') : $source));
        $data['title'] = trim($data['title'] ?? '') ?: mb_substr($remote ? rawurldecode($filename) : $filename, 0, 200);
        $entry = VideoJournalEntry::create($data + ['body' => '']);
        return redirect()->route('video-journal.show', $entry->id)->with('status', '映像已收進映像管，可以寫下小記了。');
    }

    private function validateSource(string $source): void
    {
        if (!filter_var($source, FILTER_VALIDATE_URL) || !in_array(strtolower(parse_url($source, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true)) {
            if (!preg_match('~^(?:[A-Za-z]:[\\\\/]|/|\\\\\\\\)~', $source)
                || !in_array(strtolower(pathinfo($source, PATHINFO_EXTENSION)), ['mp4', 'webm', 'ogv', 'mov', 'm4v'], true)
                || !is_file($source)) {
                throw ValidationException::withMessages(['source' => '請輸入存在的影片完整路徑，或 http / https 影片直連網址。']);
            }
        }
    }

    public function storeBatch(Request $request)
    {
        $data = $request->validate(['items' => 'required|array|min:1|max:50', 'items.*.source' => 'required|string|max:4096|distinct', 'items.*.title' => 'nullable|string|max:200', 'items.*.size' => 'required|integer|min:0', 'items.*.fingerprint' => 'required|string|regex:/^[a-f0-9]{64}$/']);
        $rows = [];
        foreach ($data['items'] as $item) {
            $source = trim($item['source']);
            $this->validateSource($source);
            if (preg_match('~^https?://~i', $source) || !is_file($source) || filesize($source) !== (int) $item['size'] || !hash_equals($item['fingerprint'], $this->sampleFingerprint($source, (int) $item['size']))) {
                throw ValidationException::withMessages(['items' => '影片來源已變動，請重新確認拖入的影片。']);
            }
            $rows[] = ['source' => $source, 'title' => trim($item['title'] ?? '') ?: mb_substr(basename(str_replace('\\', '/', $source)), 0, 200), 'body' => ''];
        }
        DB::connection('video_journal')->transaction(function () use ($rows) {
            foreach ($rows as $row) VideoJournalEntry::create($row);
        });
        $request->session()->flash('status', '已收進 '.count($rows).' 則映像。');
        return response()->json(['count' => count($rows), 'redirect' => route('video-journal.index')]);
    }

    public function show(int $id)
    {
        $entry = VideoJournalEntry::findOrFail($id);
        $remote = preg_match('~^https?://~i', $entry->source) === 1;
        return view('video-journal.show', compact('entry', 'remote'));
    }

    public function cover(int $id, int $position = 0)
    {
        abort_unless($position >= 0 && $position < 2, 404);
        // Fetch only the requested cover, without loading article or other images.
        $entry = VideoJournalEntry::query()->whereKey($id)
            ->selectRaw('json_extract(covers, ?) AS cover', ['$['.$position.']'])->firstOrFail();
        abort_unless(preg_match('~^data:(image/(?:png|jpeg|gif|webp));base64,~', $entry->cover ?? '', $match), 404);
        $bytes = base64_decode(substr($entry->cover, strlen($match[0])), true);
        abort_unless($bytes !== false, 404);
        return response($bytes, 200, ['Content-Type' => $match[1]]);
    }

    public function update(Request $request, int $id, VideoJournalContent $content)
    {
        $data = $request->validate([
            'title' => 'required|string|max:200', 'body' => 'nullable|string|max:'.VideoJournalContent::BODY_BYTES,
            'tags' => 'sometimes|array|max:5', 'tags.*' => 'required|string|max:40|distinct',
            'covers' => 'sometimes|array|max:2', 'covers.*' => 'required|string|max:28000000',
            'portraits' => 'sometimes|array|max:5', 'portraits.*' => 'required|string|max:28000000',
        ]);
        $total = strlen($data['body'] ?? '') + array_sum(array_map('strlen', [...($data['portraits'] ?? []), ...($data['covers'] ?? [])]));
        if ($total > VideoJournalContent::BODY_BYTES) {
            throw ValidationException::withMessages(['portraits' => '文章、封面與大頭照合計最多 64 MB。']);
        }
        $entry = VideoJournalEntry::findOrFail($id);
        $changes = ['title' => $data['title'], 'body' => $content->sanitize($data['body'] ?? '')];
        if (array_key_exists('tags', $data)) $changes['tags'] = array_values(array_unique(array_filter(array_map('trim', $data['tags']), fn ($tag) => $tag !== '')));
        if (array_key_exists('covers', $data)) $changes['covers'] = array_map(fn ($image) => $content->portrait($image, 'covers', '封面'), array_values($data['covers']));
        $portraits = array_key_exists('portraits', $data) ? array_map(fn ($image) => $content->portrait($image), array_values($data['portraits'])) : null;
        DB::connection('video_journal')->transaction(function () use ($entry, $changes, $portraits) {
            $entry->update($changes);
            if ($portraits === null) return;
            $existing = $entry->faces()->get();
            $kept = [];
            foreach ($portraits as $position => $image) {
                $hash = hash('sha256', base64_decode(substr($image, strpos($image, ',') + 1), true));
                // Preserve stable IDs and future features only for unchanged image bytes.
                $face = $existing->first(fn ($face) => $face->image_sha256 === $hash && !in_array($face->id, $kept, true));
                if ($face) $face->update(['position' => $position]);
                else $face = $entry->faces()->create(['position' => $position, 'image' => $image, 'image_sha256' => $hash]);
                $kept[] = $face->id;
            }
            $entry->faces()->whereNotIn('id', $kept)->delete();
        });
        return response()->json(['message' => '已儲存所有變更', 'updated_at' => $entry->updated_at->format('Y.m.d H:i'), 'body' => $entry->body, 'tags' => $entry->tags ?? [], 'covers' => $entry->covers ?? [], 'portraits' => $entry->portraits ?? []]);
    }

    public function portrait(int $id, int $position)
    {
        abort_unless($position >= 0 && $position < 5, 404);
        $face = \App\Models\VideoJournalFace::where('entry_id', $id)->where('position', $position)->firstOrFail();
        abort_unless(preg_match('~^data:(image/(?:png|jpeg|gif|webp));base64,~', $face->image, $match), 404);
        $bytes = base64_decode(substr($face->image, strlen($match[0])), true);
        abort_if($bytes === false, 404);
        return response($bytes, 200, ['Content-Type' => $match[1]]);
    }

    public function destroy(int $id)
    {
        VideoJournalEntry::findOrFail($id)->delete();
        return redirect()->route('video-journal.index')->with('status', '已從映像管拿掉，原始影片仍保留。');
    }

    public function media(int $id)
    {
        $entry = VideoJournalEntry::findOrFail($id);
        abort_if(preg_match('~^https?://~i', $entry->source) === 1, 404);
        abort_unless(is_file($entry->source), 404, '影片來源不存在或尚未連接。');
        $mime = ['webm' => 'video/webm', 'ogv' => 'video/ogg', 'mov' => 'video/quicktime'];
        return response()->file($entry->source, ['Content-Type' => $mime[strtolower(pathinfo($entry->source, PATHINFO_EXTENSION))] ?? 'video/mp4']);
    }

    public function subtitles(int $id, VideoJournalContent $content)
    {
        $entry = VideoJournalEntry::findOrFail($id);
        abort_if(preg_match('~^https?://~i', $entry->source) === 1, 404);
        $path = preg_replace('/\.[^.\\\\\/]+$/', '.srt', $entry->source);
        abort_unless($path !== $entry->source && is_file($path) && filesize($path) <= 5 * 1024 * 1024, 404);
        $text = file_get_contents($path);
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'BIG5');
        }
        return response($content->subtitles($text), 200, ['Content-Type' => 'text/vtt; charset=UTF-8']);
    }
}
