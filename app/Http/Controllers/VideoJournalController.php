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
            ->selectRaw('instr(body, ?) > 0 AS has_image', ['<img src="'])
            ->selectRaw('EXISTS (SELECT 1 FROM video_journal_faces WHERE entry_id = video_journal_entries.id) AS has_portrait')
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search) {
                $query->where('title', 'like', '%'.$search.'%')->orWhereRaw('EXISTS (SELECT 1 FROM json_each(video_journal_entries.tags) WHERE value LIKE ?)', ['%'.$search.'%']);
            }))
            ->orderByDesc('updated_at')->orderByDesc('id')->paginate(12)->withQueryString();
        return view('video-journal.index', ['entries' => $entries, 'search' => $search, 'total' => VideoJournalEntry::count()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['title' => 'nullable|string|max:200', 'source' => 'required|string|max:4096']);
        $data['source'] = trim($data['source'], " \t\n\r\0\x0B\"");
        $source = $data['source'];
        if (!filter_var($source, FILTER_VALIDATE_URL) || !in_array(strtolower(parse_url($source, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true)) {
            if (!preg_match('~^(?:[A-Za-z]:[\\\\/]|/|\\\\\\\\)~', $source)
                || !in_array(strtolower(pathinfo($source, PATHINFO_EXTENSION)), ['mp4', 'webm', 'ogv', 'mov', 'm4v'], true)
                || !is_file($source)) {
                throw ValidationException::withMessages(['source' => '請輸入存在的影片完整路徑，或 http / https 影片直連網址。']);
            }
        }
        $remote = preg_match('~^https?://~i', $source) === 1;
        $filename = basename(str_replace('\\', '/', $remote ? (parse_url($source, PHP_URL_PATH) ?: 'video') : $source));
        $data['title'] = trim($data['title'] ?? '') ?: mb_substr($remote ? rawurldecode($filename) : $filename, 0, 200);
        $entry = VideoJournalEntry::create($data + ['body' => '']);
        return redirect()->route('video-journal.show', $entry->id)->with('status', '影片已加入，開始寫下你的故事。');
    }

    public function show(int $id)
    {
        $entry = VideoJournalEntry::findOrFail($id);
        $remote = preg_match('~^https?://~i', $entry->source) === 1;
        return view('video-journal.show', compact('entry', 'remote'));
    }

    public function cover(int $id)
    {
        // Return only the first image from SQLite, not the entire rich-text article.
        $start = 'instr(body, \'<img src="\') + 10';
        $entry = VideoJournalEntry::query()->whereKey($id)
            ->selectRaw('substr(body, '.$start.', instr(substr(body, '.$start.'), \'"\') - 1) AS cover')
            ->whereRaw('instr(body, ?) > 0', ['<img src="'])->firstOrFail();
        abort_unless(preg_match('~^data:(image/(?:png|jpeg|gif|webp));base64,~', $entry->cover, $match), 404);
        $bytes = base64_decode(substr($entry->cover, strlen($match[0])), true);
        abort_unless($bytes !== false, 404);
        return response($bytes, 200, ['Content-Type' => $match[1]]);
    }

    public function update(Request $request, int $id, VideoJournalContent $content)
    {
        $data = $request->validate([
            'title' => 'required|string|max:200', 'body' => 'nullable|string|max:'.VideoJournalContent::BODY_BYTES,
            'tags' => 'sometimes|array|max:5', 'tags.*' => 'required|string|max:40|distinct',
            'portraits' => 'sometimes|array|max:5', 'portraits.*' => 'required|string|max:28000000',
        ]);
        $total = strlen($data['body'] ?? '') + array_sum(array_map('strlen', $data['portraits'] ?? []));
        if ($total > VideoJournalContent::BODY_BYTES) {
            throw ValidationException::withMessages(['portraits' => '文章與大頭照合計最多 64 MB。']);
        }
        $entry = VideoJournalEntry::findOrFail($id);
        $changes = ['title' => $data['title'], 'body' => $content->sanitize($data['body'] ?? '')];
        if (array_key_exists('tags', $data)) $changes['tags'] = array_values(array_unique(array_filter(array_map('trim', $data['tags']), fn ($tag) => $tag !== '')));
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
        return response()->json(['message' => '已儲存所有變更', 'updated_at' => $entry->updated_at->format('Y.m.d H:i'), 'body' => $entry->body, 'tags' => $entry->tags ?? [], 'portraits' => $entry->portraits ?? []]);
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
        return redirect()->route('video-journal.index')->with('status', '文章已刪除，原始影片保留。');
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
