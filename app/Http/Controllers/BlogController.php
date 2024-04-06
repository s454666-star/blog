<?php

namespace App\Http\Controllers;

use App\Models\Article;
use Illuminate\Http\Request;

class BlogController
{
    public function index(Request $request)
    {
        $query = Article::with('images')
            ->where('is_disabled', 0)
            ->where('source_type', 1);

        if ($search = $request->input('search')) {
            $query->where(function ($query) use ($search) {
                $query->where('title', 'LIKE', "%{$search}%")
                    ->orWhere('password', 'LIKE', "%{$search}%")
                    ->orWhere('https_link', 'LIKE', "%{$search}%");
            });
        }

        $articles        = $query->paginate(30);
        $isPreservedView = request()->is('blog/show-preserved'); // 判斷是否在顯示保留的頁面

        return view('blog.index', compact('articles', 'search', 'isPreservedView'));
    }

    public function destroy($articleId)
    {
        // 透過文章 ID 查找文章，然後將 is_disabled 設為 1
        $article              = Article::findOrFail($articleId);
        $article->is_disabled = 1;
        $article->save();

        // 儲存更改後，重定向回文章列表，並帶有成功消息
        return redirect()->route('blog.index')->with('success', 'Article has been disabled successfully.');
    }

    public function batchDelete(Request $request)
    {
        $selectedArticles = $request->input('selected_articles', []);

        if (count($selectedArticles) > 0) {
            Article::whereIn('article_id', $selectedArticles)->update([ 'is_disabled' => 1 ]);
        }

        return redirect()->route('blog.index')->with('success', '選中的文章已成功刪除。');
    }

    public function preserve(Request $request)
    {
        $selectedArticles = $request->input('selected_articles', []);
        if (count($selectedArticles) > 0) {
            Article::whereIn('article_id', $selectedArticles)->update([ 'is_disabled' => 2 ]);
            return redirect()->route('blog.index')->with('success', '選中的文章已成功保留。');
        } else {
            return redirect()->route('blog.index')->with('error', '沒有選擇文章。');
        }
    }

    public function showPreserved(Request $request)
    {
        $articles = Article::where('is_disabled', 2)->paginate(30);
        // Pass an additional variable to indicate the preserved view status
        return view('blog.index', [
            'articles' => $articles,
            'search' => $request->input('search'),
            'isPreservedView' => true // Assuming true indicates that it is the preserved view
        ]);
    }
}