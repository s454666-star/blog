<?php

    namespace App\Http\Controllers;

    use App\Models\Article;
    use Illuminate\Http\RedirectResponse;
    use Illuminate\Http\Request;
    use Illuminate\View\View;

    class BlogBtController
    {
        public function index(Request $request): View
        {
            $search = $request->input('search');

            $query = Article::with('images')
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
            $article              = Article::findOrFail($articleId);
            $article->is_disabled = 1;
            $article->save();

            return redirect()->route('blogBt.index')->with('success', 'Article has been disabled successfully.');
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
