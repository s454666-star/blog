<?php

namespace App\Http\Controllers;

use App\Models\Article;
use Illuminate\Http\Request;

class BlogController
{
    public function index(Request $request)
    {
        $query = Article::with('images')->where('is_disabled', 0);

        if ($search = $request->input('search')) {
            $query->where(function ($query) use ($search) {
                $query->where('title', 'LIKE', "%{$search}%")
                    ->orWhere('password', 'LIKE', "%{$search}%")
                    ->orWhere('https_link', 'LIKE', "%{$search}%");
            });
        }

        $articles = $query->paginate(30);

        return view('blog.index', compact('articles', 'search'));
    }

    public function destroy($articleId)
    {
        // 透過文章 ID 查找文章，然後將 is_disabled 設為 1
        $article = Article::findOrFail($articleId);
        $article->is_disabled = 1;
        $article->save();

        // 儲存更改後，重定向回文章列表，並帶有成功消息
        return redirect()->route('blog.index')->with('success', 'Article has been disabled successfully.');
    }
}