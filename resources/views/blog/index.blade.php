@extends('layouts.app')
<link href="{{ asset('css/blog.css') }}" rel="stylesheet">

@section('content')
    <div class="container">
        <div id="toast" class="toast">密碼已複製到剪貼簿</div>

        <form action="{{ url('blog') }}" method="GET" class="search-form">
            <input type="text" name="search" placeholder="搜尋文章..." value="{{ request()->search }}">
            <button type="submit">搜尋</button>
        </form>

        <div class="actions">
            <form id="action-form" action="{{ url('blog/batch-delete') }}" method="POST" class="action-form">
                @csrf
                @method('DELETE')
                <button type="submit" class="custom-btn batch-delete-btn">批次刪除</button>
            </form>
            <!-- 移出 preserve 按鈕的 form 外面 -->
            <button type="button" class="custom-btn preserve-btn">保留</button>
            <button type="button" class="custom-btn toggle-view-btn" id="toggle-view">{{ $isPreservedView ? '返回博客' : '顯示保留' }}</button>
            <form id="preserve-form" action="{{ url('blog/preserve') }}" method="POST" style="display: none;">
                @csrf
                <!-- 隱藏的輸入元素將在JavaScript中動態添加 -->
            </form>
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
                    <button class="password-btn" data-password="{{ $article->password }}">
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
                target.style.display = (target.style.display === 'none' || target.style.display === '') ? 'flex' : 'none';
                button.textContent = (target.style.display === 'flex') ? '隱藏圖片' : '顯示圖片';
            } else {
                console.error('toggleImages called with null target or button:', target, button); // 如果目標或按鈕為 null，輸出錯誤信息
            }
        }

        const preserveButton = document.querySelector('.preserve-btn');
        if (preserveButton) {
            preserveButton.addEventListener('click', function (event) {
                event.preventDefault();
                handlePreserve();
            });
        }

        const showPreservedBtn = document.getElementById('show-preserved');

        showPreservedBtn.addEventListener('click', function() {
            window.location.href = '{{ url('blog/show-preserved') }}';  // Laravel生成的URL
        });

        const toggleViewBtn = document.getElementById('toggle-view');

        toggleViewBtn.addEventListener('click', function() {
            const currentPath = window.location.pathname;
            console.log("Current path:", currentPath); // Debug: Log current path to see what it is.
            if (currentPath.includes('show-preserved')) {
                window.location.href = '{{ url('blog') }}';
            } else {
                window.location.href = '{{ url('blog/show-preserved') }}';
            }
        });
    });
    document.addEventListener('DOMContentLoaded', function () {
        const toggleViewBtn = document.getElementById('toggle-view');

        toggleViewBtn.addEventListener('click', function() {
            const currentPath = window.location.pathname;
            console.log("Current path:", currentPath); // Debug: Log current path to see what it is.
            if (currentPath.includes('show-preserved')) {
                window.location.href = '{{ url('blog') }}';
            } else {
                window.location.href = '{{ url('blog/show-preserved') }}';
            }
        });
    });
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.password-btn').forEach(button => {
            button.addEventListener('click', function (event) {
                event.preventDefault(); // 阻止按鈕的預設行為
                const password = this.getAttribute('data-password');
                console.log('密碼按鈕被點擊，密碼:', password);
                copyPassword(password);
            });
        });
    });

    function copyPassword(password) {
        console.log('copyPassword 函數被觸發，密碼:', password);
        const tempInput = document.createElement('input');
        document.body.appendChild(tempInput);
        tempInput.value = password;
        tempInput.select();
        try {
            const successful = document.execCommand('copy');
            console.log('複製密碼' + (successful ? '成功' : '失敗'));
            showToast(); // 顯示 Toast 訊息
        } catch (err) {
            console.error('無法複製密碼', err);
        }
        document.body.removeChild(tempInput);
    }

    function showToast() {
        const toast = document.getElementById('toast');
        toast.className = "toast show";
        setTimeout(function () {
            toast.className = toast.className.replace("show", "");
        }, 500);
    }

    document.querySelectorAll('.custom-btn').forEach(button => {
        button.addEventListener('click', function () {
            // 確保這裡不修改任何影響布局的CSS屬性
        });
    });

    document.querySelector('.preserve-btn').addEventListener('click', function (event) {
        event.preventDefault();
        const form = document.getElementById('preserve-form');
        const selectedArticles = document.querySelectorAll('.article-checkbox:checked');

        // 清除之前添加的輸入元素
        form.innerHTML = '';
        const csrfField = document.createElement('input');
        csrfField.type = 'hidden';
        csrfField.name = '_token';
        csrfField.value = '{{ csrf_token() }}';
        form.appendChild(csrfField);

        selectedArticles.forEach(article => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'selected_articles[]';
            input.value = article.value;
            form.appendChild(input);
        });

        if (selectedArticles.length > 0) {
            form.submit();  // 提交表單
        } else {
            alert('請選擇至少一篇文章來保留。');
        }
    });

    function handlePreserve() {
        const form = document.getElementById('preserve-form');
        const selectedArticles = document.querySelectorAll('.article-checkbox:checked');

        // 清除之前添加的輸入元素
        form.innerHTML = '';

        // 創建 CSRF 令牌輸入元素
        const csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = '_token';
        csrfInput.value = '{{ csrf_token() }}'; // 確保這行代碼正確生成 CSRF 令牌
        form.appendChild(csrfInput);

        selectedArticles.forEach(article => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'selected_articles[]';
            input.value = article.value;
            form.appendChild(input);
        });

        if (selectedArticles.length > 0) {
            form.submit();  // 提交表單
        } else {
            alert('請選擇至少一篇文章來保留。');
        }
    }

</script>
