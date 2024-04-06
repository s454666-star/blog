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
            // 在頁面加載時明確設置每個圖片容器的顯示樣式為 'flex'
            document.querySelectorAll('.article-images').forEach(imagesDiv => {
                imagesDiv.style.display = 'flex';
            });

            document.querySelectorAll('.toggle-images').forEach(button => {
                button.textContent = '隱藏圖片'; // 確保按鈕預設文字是「隱藏圖片」

                button.addEventListener('click', function () {
                    const target = document.querySelector(this.getAttribute('data-target'));
                    console.log('Button clicked, target:', target); // 輸出目標信息
                    toggleImages(target, this);
                });
            });

            document.querySelectorAll('.article-images img').forEach(img => {
                img.addEventListener('click', function () {
                    const articleContainer = this.closest('.article');
                    const toggleButton = articleContainer ? articleContainer.querySelector('.toggle-images') : null;
                    console.log('Image clicked, toggleButton:', toggleButton); // 輸出按鈕信息
                    if (toggleButton) {
                        const imagesDiv = articleContainer.querySelector('.article-images');
                        toggleImages(imagesDiv, toggleButton);
                    } else {
                        console.error('ToggleButton not found for image:', this); // 如果按鈕未找到，輸出錯誤信息
                    }
                });
            });

            function toggleImages(target, button) {
                if (target && button) { // 確保目標和按鈕都存在
                    console.log('Toggling images for target:', target, 'button:', button); // 輸出正在切換的目標和按鈕信息
                    // 調整判斷邏輯，考慮空字符串的情況也視為 'flex'
                    if (target.style.display === 'none' || target.style.display === '' || target.style.display === 'flex') {
                        target.style.display = (target.style.display === 'none' || target.style.display === '') ? 'flex' : 'none';
                        button.textContent = (target.style.display === 'flex') ? '隱藏圖片' : '顯示圖片';
                    }
                } else {
                    console.error('toggleImages called with null target or button:', target, button); // 如果目標或按鈕為 null，輸出錯誤信息
                }
            }
        });
    </script>
@endsection
