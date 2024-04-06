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
                        <input type="checkbox" name="selected_articles[]" value="{{ $article->article_id }}"
                               class="article-checkbox">
                        <h2>{{ $article->title }}</h2>
                    </div>
                    <div class="button-group">
                        <button type="button" class="toggle-images" data-target="#images-{{ $article->article_id }}">
                            隱藏圖片
                        </button>
                        <!-- 密碼按鈕和隱藏的輸入框 -->
                        <button class="password-btn" data-password="{{ $article->password }}"
                                onclick="copyPassword(this)">
                            密碼：{{ $article->password }}
                        </button>
                        <a href="{{ $article->https_link }}" target="_blank" class="link-btn">連結</a>
                    </div>

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
@endsection


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

    function copyPassword(buttonElement) {
        const password = buttonElement.getAttribute('data-password');
        console.log('嘗試複製的密碼:', password);

        // 檢查 navigator.clipboard 是否可用
        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            console.log('使用 navigator.clipboard 方法複製');
            navigator.clipboard.writeText(password).then(() => {
                console.log('密碼成功複製到剪貼簿');
                alert('密碼已複製到剪貼簿');
            }).catch(err => {
                console.error('使用 navigator.clipboard 複製失敗，錯誤信息:', err);
                // 如果 navigator.clipboard 失敗，使用後備方案
                fallbackCopyTextToClipboard(password);
            });
        } else {
            console.log('navigator.clipboard 不可用，使用後備方案');
            // 如果 navigator.clipboard 不可用，直接使用後備方案
            fallbackCopyTextToClipboard(password);
        }
    }

    function fallbackCopyTextToClipboard(text) {
        console.log('執行後備方案複製');
        const textArea = document.createElement("textarea");
        textArea.value = text;
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();

        try {
            const successful = document.execCommand('copy');
            const msg = successful ? '成功' : '失敗';
            console.log('後備方案複製' + msg);
            alert('密碼複製' + msg);
        } catch (err) {
            console.error('後備方案，密碼複製失敗', err);
        }

        document.body.removeChild(textArea);
    }


</script>

