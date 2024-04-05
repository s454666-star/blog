@extends('layouts.app')
<link href="{{ asset('css/blog.css') }}" rel="stylesheet">

@section('content')
    <div class="container">
        <form action="{{ url('blog') }}" method="GET" class="search-form">
            <input type="text" name="search" placeholder="搜尋文章..." value="{{ request()->search }}">
            <button type="submit">搜尋</button>
        </form>

        <form id="batch-delete-form" action="{{ url('blog/batch-delete') }}" method="POST">
            @csrf
            @method('DELETE')

            <div class="batch-delete">
                <button type="submit" id="batch-delete-btn" class="btn custom-btn">批次刪除</button>
            </div>

            @foreach($articles as $index => $article)
                <div id="article-{{ $article->article_id }}" class="article">
                    <div class="article-header">
                        <input type="checkbox" name="selected_articles[]" value="{{ $article->article_id }}" class="article-checkbox">
                        <h2>{{ $article->title }}</h2>
                    </div>
                    <button type="button" class="toggle-images" data-target="#images-{{ $article->article_id }}">隱藏圖片</button>

                    <div id="images-{{ $article->article_id }}" class="article-images">
                        @foreach($article->images as $image)
                            <img src="{{ $image->image_path }}" class="article-image">
                        @endforeach
                    </div>
                    <div class="article-info">
                        <p>密碼：{{ $article->password }}</p>
                        <a href="{{ $article->https_link }}" target="_blank">連結</a>
                    </div>
                </div>
            @endforeach

            {{ $articles->appends(request()->query())->links() }}
        </form>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.toggle-images').forEach(button => {
                button.addEventListener('click', function() {
                    const target = document.querySelector(this.getAttribute('data-target'));
                    if (target.style.display === 'none') {
                        target.style.display = 'flex';
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
