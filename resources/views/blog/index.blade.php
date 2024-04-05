@extends('layouts.app')
<link href="{{ asset('css/blog.css') }}" rel="stylesheet">

@section('content')
    <div class="container">
        <form action="{{ url('blog') }}" method="GET" class="search-form">
            <input type="text" name="search" placeholder="Search articles..." value="{{ request()->search }}">
            <button type="submit">Search</button>
        </form>
        @foreach($articles as $index => $article)
            <div id="article-{{ $article->article_id }}" class="article">
                <h2>{{ $article->title }}</h2>
                @foreach($article->images as $image)
                    <img src="{{ $image->image_path }}" style="max-width: 100%; height: auto;">
                @endforeach
                <div class="article-info">
                    <p>Password: {{ $article->password }}</p>
                    <a href="{{ $article->https_link }}" target="_blank">Link</a>
                </div>
                <form action="{{ route('articles.destroy', $article->article_id) }}" method="POST" onsubmit="return confirm('Are you sure?');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-danger">Delete</button>
                </form>
            </div>
        @endforeach
        {{ $articles->links() }}
        {{ $articles->appends(request()->query())->links() }}
    </div>
    <div class="pagination-info">
        Page {{ $articles->currentPage() }} of {{ $articles->lastPage() }}
    </div>

    <script>
        document.addEventListener('keydown', function(event) {
            const articles = [...document.querySelectorAll('.article')];
            let currentArticleIndex = articles.findIndex(article => {
                const rect = article.getBoundingClientRect();
                return rect.top < window.innerHeight / 2 && rect.bottom > 0;
            });

            if (event.code === 'PageUp') {
                event.preventDefault(); // 防止页面默认的向上滚动行为
                if (currentArticleIndex > 0) {
                    const previousArticle = articles[currentArticleIndex - 1];
                    previousArticle.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            } else if (event.code === 'PageDown') {
                event.preventDefault(); // 防止页面默认的向下滚动行为
                if (currentArticleIndex < articles.length - 1) {
                    const nextArticle = articles[currentArticleIndex + 1];
                    nextArticle.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }
        });
    </script>
@endsection
