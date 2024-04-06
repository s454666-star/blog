@extends('layouts.app')
<link href="{{ asset('css/blog.css') }}" rel="stylesheet">

@section('content')
    <div class="container">
        <form action="{{ url('blog-bt') }}" method="GET" class="search-form">
            <input type="text" name="search" placeholder="搜尋文章..." value="{{ request()->search }}">
            <button type="submit">搜尋</button>
        </form>

        <form id="batch-delete-form" action="{{ url('blog/batch-delete-bt') }}" method="POST">
            @csrf
            @method('DELETE')

            <div class="batch-delete">
                <button type="submit" id="batch-delete-btn" class="btn custom-btn">批次刪除</button>
            </div>

            @foreach($articles as $index => $article)
                <div id="article-{{ $article->article_id }}" class="article">
                    <div class="article-header">
                        <input type="checkbox" name="selected_articles[]" value="{{ $article->article_id }}"
                               class="article-checkbox">
                        <h2>{{ $article->title }}</h2>
                    </div>

                    <div class="article-info">
                        <button class="seed-link-btn" onclick="window.open('{{ $article->password }}', '_blank')">
                            種子鏈結
                        </button>
                        <button class="download-btn" onclick="window.open('{{ $article->https_link }}', '_blank')">
                            下載
                        </button>
                    </div>

                    <button type="button" class="toggle-images" data-target="#images-{{ $article->article_id }}">
                        隱藏圖片
                    </button>

                    <div id="images-{{ $article->article_id }}" class="article-images">
                        @foreach($article->images as $image)
                            <img src="{{ $image->image_path }}" class="article-image">
                        @endforeach
                    </div>
                </div>
            @endforeach

            {{ $articles->appends(request()->query())->links() }}
        </form>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            initializePage();

            document.addEventListener('click', function (e) {
                if (e.target.matches('.pagination a')) {
                    e.preventDefault();
                    const url = e.target.href;
                    fetch(url)
                        .then(response => response.text())
                        .then(html => {
                            const parser = new DOMParser();
                            const doc = parser.parseFromString(html, "text/html");
                            const newContent = doc.querySelector('.container').innerHTML;
                            document.querySelector('.container').innerHTML = newContent;
                            initializePage();
                        })
                        .catch(error => console.error('Error loading the page: ', error));
                }
            });
        });

        function initializePage() {
            document.querySelectorAll('.article-images img').forEach(img => {
                img.onload = () => {
                    if (img.naturalHeight > 3000) {
                        img.closest('.article-images').classList.add('full-width-image');
                        img.style.transformOrigin = 'top left';
                    } else if (img.naturalHeight > 2000) {
                        img.style.transform = 'scale(2)';
                        img.style.transformOrigin = 'top left';
                    }
                };
            });

            document.querySelectorAll('.toggle-images').forEach(button => {
                button.addEventListener('click', function () {
                    const target = document.querySelector(this.getAttribute('data-target'));
                    if (target.style.display === 'none' || target.style.display === '') {
                        target.style.display = 'flex';
                        this.textContent = '隱藏圖片';
                    } else {
                        target.style.display = 'none';
                        this.textContent = '顯示圖片';
                    }
                });
            });

            document.querySelectorAll('.article-images').forEach(imagesDiv => {
                imagesDiv.style.display = 'flex';
            });
        }

    </script>
@endsection
