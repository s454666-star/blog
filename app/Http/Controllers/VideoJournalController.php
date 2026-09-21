<?php

namespace App\Http\Controllers;

use App\Models\VideoJournalEntry;
use App\Services\VideoJournalContent;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class VideoJournalController extends Controller
{
    public function index(Request $request)
    {
        $search = mb_substr(trim((string) $request->query('q', '')), 0, 200);
        $entries = VideoJournalEntry::query()->select(['id', 'title', 'created_at', 'updated_at'])
            ->when($search !== '', fn ($query) => $query->where('title', 'like', '%'.$search.'%'))
            ->orderByDesc('updated_at')->orderByDesc('id')->paginate(12)->withQueryString();
        return view('video-journal.index', ['entries' => $entries, 'search' => $search, 'total' => VideoJournalEntry::count()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['title' => 'required|string|max:200', 'source' => 'required|string|max:4096']);
        $data['source'] = trim($data['source'], " \t\n\r\0\x0B\"");
        $source = $data['source'];
        if (!filter_var($source, FILTER_VALIDATE_URL) || !in_array(strtolower(parse_url($source, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true)) {
            if (!preg_match('~^(?:[A-Za-z]:[\\\\/]|/|\\\\\\\\)~', $source)
                || !in_array(strtolower(pathinfo($source, PATHINFO_EXTENSION)), ['mp4', 'webm', 'ogv', 'mov', 'm4v'], true)
                || !is_file($source)) {
                throw ValidationException::withMessages(['source' => '請輸入存在的影片完整路徑，或 http / https 影片直連網址。']);
            }
        }
        $entry = VideoJournalEntry::create($data + ['body' => '']);
        return redirect()->route('video-journal.show', $entry->id)->with('status', '影片已加入，開始寫下你的故事。');
    }

    public function show(int $id)
    {
        $entry = VideoJournalEntry::findOrFail($id);
        $remote = preg_match('~^https?://~i', $entry->source) === 1;
        return view('video-journal.show', compact('entry', 'remote'));
    }

    public function update(Request $request, int $id, VideoJournalContent $content)
    {
        $data = $request->validate(['title' => 'required|string|max:200', 'body' => 'nullable|string|max:12000000']);
        $entry = VideoJournalEntry::findOrFail($id);
        $entry->update(['title' => $data['title'], 'body' => $content->sanitize($data['body'] ?? '')]);
        return response()->json(['message' => '已儲存所有變更', 'updated_at' => $entry->updated_at->format('Y.m.d H:i'), 'body' => $entry->body]);
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
