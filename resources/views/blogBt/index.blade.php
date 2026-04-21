@extends('layouts.app')
<link href="{{ asset('css/blog.css') }}" rel="stylesheet">

@section('content')
    <div class="container">
        @if (session('success'))
            <div class="bt-flash bt-flash-success">{{ session('success') }}</div>
        @endif

        @if (session('error'))
            <div class="bt-flash bt-flash-error">{{ session('error') }}</div>
        @endif

        <!-- Search Form -->
        <form action="{{ route('blogBt.index') }}" method="GET" class="search-form">
            <input type="text" name="search" placeholder="搜尋文章..." value="{{ request()->search }}">
            <button type="submit">搜尋</button>
        </form>

        <!-- Batch Delete Form -->
        <form id="batch-delete-form" action="{{ url('blog/batch-delete-bt') }}" method="POST">
            @csrf
            @method('DELETE')

            <div class="batch-delete">
                <button type="submit" id="batch-delete-btn" class="btn custom-btn" style="background-color: #e74c3c !important;">
                    批次刪除
                </button>
            </div>

            <!-- Articles Loop -->
            @foreach($articles as $index => $article)
                <div id="article-{{ $article->article_id }}" class="article">
                    <!-- Article Header -->
                    <div class="article-header">
                        <input type="checkbox" name="selected_articles[]" value="{{ $article->article_id }}"
                               class="article-checkbox">
                        <h2>{{ $article->title }}</h2>
                    </div>

                    <!-- Article Info Buttons -->
                    <div class="article-info">
                        <!-- 重要：加上 type="button"，避免提交表單 -->
                        <button type="button"
                                class="seed-link-btn"
                                data-seed-link="{{ e($article->password) }}">
                            種子鏈結
                        </button>

                        <!-- 重要：加上 type="button"，避免提交表單 -->
                        <button type="button"
                                class="download-btn"
                                onclick="window.open('{{ $article->https_link }}', '_blank', 'noopener')">
                            下載
                        </button>

                        @if ((int) $article->images_count === 0)
                            <span class="bt-image-state bt-image-state-missing">目前沒有圖片，可直接重跑</span>
                            <button type="button"
                                    class="rerun-btn bt-rerun-btn"
                                    data-rerun-url="{{ route('blogBt.rerun', $article->article_id) }}"
                                    data-rerun-title="{{ e($article->title) }}">
                                重跑
                            </button>
                        @else
                            <span class="bt-image-state bt-image-state-ready">圖片 {{ $article->images_count }} 張</span>
                        @endif
                    </div>

                    @if ((int) $article->images_count > 0)
                        <!-- Toggle Images Button -->
                        <button type="button" class="toggle-images" data-target="#images-{{ $article->article_id }}" style="background-color: #8e44ad !important;">
                            隱藏圖片
                        </button>
                    @endif

                    <!-- Article Images -->
                    <div id="images-{{ $article->article_id }}" class="article-images">
                        @foreach($article->images as $image)
                            <img src="{{ $image->image_path }}" class="article-image" alt="Article Image">
                        @endforeach
                    </div>
                </div>
            @endforeach

            <!-- Pagination Links -->
            <div class="pagination-container">
                {{ $articles->appends(request()->query())->links() }}
            </div>
        </form>

        <form id="bt-rerun-form" method="POST" hidden>
            @csrf
        </form>
    </div>

    <!-- Toast Notification -->
    <div id="toast" class="toast"></div>

    <!-- JavaScript -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            initializePage();

            // Handle Pagination Clicks (AJAX 置換)
            document.addEventListener('click', function (e) {
                if (e.target.matches('.pagination a') || e.target.closest('.pagination a')) {
                    e.preventDefault();
                    const link = e.target.closest('.pagination a');
                    if (link) {
                        const url = link.href;
                        fetch(url)
                            .then(response => response.text())
                            .then(html => {
                                const parser = new DOMParser();
                                const doc = parser.parseFromString(html, "text/html");
                                const newContent = doc.querySelector('.container').innerHTML;
                                document.querySelector('.container').innerHTML = newContent;
                                initializePage();
                                showToast('頁面已更新');
                            })
                            .catch(error => {
                                console.error('Error loading the page: ', error);
                                showToast('載入頁面時發生錯誤');
                            });
                    }
                }
            });
        });

        /**
         * Initializes event listeners for the page.
         */
        function initializePage() {
            // Initialize Toggle Images Buttons
            document.querySelectorAll('.toggle-images').forEach(button => {
                button.addEventListener('click', function () {
                    const targetSelector = this.getAttribute('data-target');
                    const target = document.querySelector(targetSelector);
                    if (target.style.display === 'none' || target.style.display === '') {
                        target.style.display = 'flex';
                        this.textContent = '隱藏圖片';
                    } else {
                        target.style.display = 'none';
                        this.textContent = '顯示圖片';
                    }
                });
            });

            // Ensure all images are visible by default
            document.querySelectorAll('.article-images').forEach(imagesDiv => {
                imagesDiv.style.display = 'flex';
            });

            // Initialize Seed Link Button Clicks
            document.querySelectorAll('.seed-link-btn').forEach(btn => {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    const raw = this.getAttribute('data-seed-link') || '';
                    openSeedOrUrl(raw);
                });
            });

            document.querySelectorAll('.bt-rerun-btn').forEach(btn => {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();

                    const rerunUrl = this.getAttribute('data-rerun-url') || '';
                    const title = this.getAttribute('data-rerun-title') || '這篇 BT 文章';
                    if (!rerunUrl) {
                        showToast('找不到重跑網址');
                        return;
                    }

                    if (!window.confirm(`確定要重跑這篇無圖片文章嗎？\n\n${title}`)) {
                        return;
                    }

                    const rerunForm = document.getElementById('bt-rerun-form');
                    if (!rerunForm) {
                        showToast('找不到重跑表單');
                        return;
                    }

                    rerunForm.setAttribute('action', rerunUrl);
                    rerunForm.submit();
                });
            });

            // Initialize Image Click Events
            initializeImageClicks();
        }

        /**
         * Sets up event listeners for image interactions.
         */
        function initializeImageClicks() {
            document.querySelectorAll('.article-image').forEach(image => {
                // Left-Click Event: 勾選 + 展/收圖
                image.addEventListener('click', function (e) {
                    if (e.button !== 0) return; // 只處理左鍵

                    const articleDiv = this.closest('.article');
                    if (!articleDiv) return;

                    const checkbox = articleDiv.querySelector('.article-checkbox');
                    const toggleButton = articleDiv.querySelector('.toggle-images');
                    const imagesDiv = articleDiv.querySelector('.article-images');

                    if (checkbox) {
                        checkbox.checked = true;
                    }

                    if (imagesDiv.style.display === 'none' || imagesDiv.style.display === '') {
                        imagesDiv.style.display = 'flex';
                        toggleButton.textContent = '隱藏圖片';
                    } else {
                        imagesDiv.style.display = 'none';
                        toggleButton.textContent = '顯示圖片';
                    }
                });

                // Right-Click Event: 開種子 + 觸發批次刪除
                image.addEventListener('contextmenu', function (e) {
                    e.preventDefault();

                    const articleDiv = this.closest('.article');
                    if (!articleDiv) return;

                    const checkbox = articleDiv.querySelector('.article-checkbox');
                    const seedButton = articleDiv.querySelector('.seed-link-btn');

                    if (checkbox) {
                        checkbox.checked = true;
                    }

                    let seedLink = '';
                    if (seedButton) {
                        seedLink = seedButton.getAttribute('data-seed-link') || '';
                    }

                    // 先開連結（使用使用者互動手勢，較不會被攔）
                    if (seedLink) {
                        openSeedOrUrl(seedLink);
                    } else {
                        showToast('未找到此文章的種子鏈結');
                    }

                    // 再延後觸發批次刪除，避免阻擋彈窗
                    const batchDeleteForm = document.getElementById('batch-delete-form');
                    if (batchDeleteForm) {
                        setTimeout(() => batchDeleteForm.submit(), 0);
                    }

                    showToast('批次刪除已觸發');
                });
            });
        }

        /**
         * 開啟種子或 URL，並做基本規整以避免「無協定」的情況。
         */
        function openSeedOrUrl(raw) {
            const link = normalizeLink(raw);
            if (!link) {
                showToast('沒有可開啟的鏈結');
                return;
            }
            try {
                const w = window.open(link, '_blank', 'noopener');
                // 若被瀏覽器阻擋，改用動態 <a> 觸發
                if (!w) {
                    const a = document.createElement('a');
                    a.href = link;
                    a.target = '_blank';
                    a.rel = 'noopener noreferrer';
                    document.body.appendChild(a);
                    a.click();
                    a.remove();
                }
            } catch (err) {
                console.error('Open link error:', err);
                showToast('開啟連結失敗');
            }
        }

        /**
         * 將可能缺少協定的字串轉為瀏覽器可開啟的形式。
         */
        function normalizeLink(raw) {
            if (!raw) return '';
            const s = raw.trim();
            if (/^(magnet:|https?:\/\/)/i.test(s)) return s;
            if (/^www\./i.test(s)) return 'https://' + s;
            return s;
        }

        /**
         * Displays a toast notification with the given message.
         */
        function showToast(message) {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.className = 'toast show';
            setTimeout(() => {
                toast.className = toast.className.replace('show', '');
            }, 3000);
        }
    </script>
@endsection
