<?php

namespace App\Http\Controllers;

use App\Models\Article;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\View\View;
use Throwable;

class BlogBtController
{
    public function index(Request $request): View
    {
        $search = $request->input('search');

        $query = Article::query()
            ->with('images')
            ->withCount('images')
            ->where('is_disabled', 0)
            ->where('source_type', 2);

        if ($search) {
            $query->where(function ($query) use ($search) {
                $query->where('title', 'LIKE', "%{$search}%")
                    ->orWhere('password', 'LIKE', "%{$search}%")
                    ->orWhere('https_link', 'LIKE', "%{$search}%");
            });
        }

        $articles = $query->paginate(30);

        return view('blogBt.index', compact('articles', 'search'));
    }

    public function destroy($articleId): RedirectResponse
    {
        $article = Article::findOrFail($articleId);
        $article->is_disabled = 1;
        $article->save();

        return redirect()->route('blogBt.index')->with('success', 'Article has been disabled successfully.');
    }

    public function rerun(int $articleId): RedirectResponse
    {
        $article = Article::query()
            ->where('article_id', $articleId)
            ->where('source_type', 2)
            ->where('is_disabled', 0)
            ->firstOrFail();

        $detailUrl = trim((string) $article->detail_url);
        if ($detailUrl === '') {
            return back()->with('error', '這篇 BT 文章沒有 detail URL，無法重跑。');
        }

        try {
            Artisan::call('bt:reimport', ['url' => $detailUrl]);
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'BT 重跑失敗：' . $e->getMessage());
        }

        return back()->with('success', 'BT 重跑完成：' . $article->title);
    }

    public function batchDelete(Request $request): RedirectResponse
    {
        $selectedArticles = $request->input('selected_articles', []);

        if (count($selectedArticles) > 0) {
            Article::whereIn('article_id', $selectedArticles)->update(['is_disabled' => 1]);
        }

        return redirect()->route('blogBt.index')->with('success', '選中的文章已成功刪除。');
    }
}
