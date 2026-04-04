<?php

namespace App\Http\Controllers;

use App\Services\VideoRerunDiffService;
use App\Services\VideoRerunSyncActionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class VideoRerunSyncController extends Controller
{
    public function index(Request $request, VideoRerunDiffService $diffService): View
    {
        $mode = in_array($request->query('mode'), ['all', 'missing', 'extra'], true)
            ? (string) $request->query('mode')
            : 'all';
        $search = trim((string) $request->query('q', ''));
        $groups = $diffService->diffGroups($search, $mode);
        $issues = $diffService->issueEntries();
        $latestRun = $diffService->latestRun();

        return view('videos.rerun-sync.index', [
            'groups' => $groups,
            'issues' => $issues,
            'latestRun' => $latestRun,
            'mode' => $mode,
            'search' => $search,
            'perPage' => (int) config('video_rerun_sync.ui.per_page', 40),
            'flashResult' => session('video_rerun_sync_result'),
        ]);
    }

    public function apply(Request $request, VideoRerunSyncActionService $actionService): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'in:delete_extras,fill_missing'],
            'hashes' => ['required', 'array', 'min:1'],
            'hashes.*' => ['required', 'string', 'size:40'],
            'mode' => ['sometimes', 'nullable', 'in:all,missing,extra'],
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $result = $actionService->apply($validated['action'], $validated['hashes']);
        $result['action'] = $validated['action'];

        return redirect()
            ->route('videos.rerun-sync.index', array_filter([
                'mode' => $validated['mode'] ?? 'all',
                'q' => $validated['q'] ?? '',
            ], static fn ($value) => $value !== ''))
            ->with('video_rerun_sync_result', $result);
    }
}
