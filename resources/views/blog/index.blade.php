@extends('layouts.app')
<link href="{{ asset('css/blog.css') }}" rel="stylesheet">

@section('content')
    <div class="container">
        <form action="{{ url('blog') }}" method="GET" class="search-form">
            <input type="text" name="search" placeholder="搜尋文章..." value="{{ request()->search }}">
            <button type="submit">搜尋</button>
        </form>
        @foreach($articles as $index => $article)
            <div id="article-{{ $article->article_id }}" class="article">
                <h2>{{ $article->title }}</h2>
                <!-- 使用 $article->article_id 作為數據目標的唯一標識符 -->
                <button type="button" class="toggle-images" data-target="#images-{{ $article->article_id }}">隱藏圖片</button>

                <!-- 圖片容器的 ID 與按鈕的 data-target 屬性一致，確保唯一性 -->
                <div id="images-{{ $article->article_id }}" class="article-images">
                    @foreach($article->images as $image)
                        <img src="{{ $image->image_path }}" style="max-width: 100%; height: auto;">
                    @endforeach
                </div>
                <div class="article-info">
                    <p>密碼：{{ $article->password }}</p>
                    <a href="{{ $article->https_link }}" target="_blank">連結</a>
                </div>
                <form action="{{ route('articles.destroy', $article->article_id) }}" method="POST" onsubmit="return confirm('確定刪除？');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-danger">刪除</button>
                </form>
            </div>
        @endforeach
        {{ $articles->links() }}
        {{ $articles->appends(request()->query())->links() }}
    </div>
    <div class="pagination-info">
        第 {{ $articles->currentPage() }} 頁，共 {{ $articles->lastPage() }} 頁
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.toggle-images').forEach(button => {
                button.addEventListener('click', function () {
                    const target = document.querySelector(this.getAttribute('data-target'));
                    if (target.style.display === 'none') {
                        target.style.display = 'block';
                        this.textContent = '隱藏圖片';
                    } else {
                        target.style.display = 'none';
                        this.textContent = '顯示圖片';
                    }
                });
            });
        });
    </script>
@endsection
