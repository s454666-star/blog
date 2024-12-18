@extends('layouts.app')
<link href="{{ asset('css/blog.css') }}" rel="stylesheet">

@section('content')
    <div class="container">
        <!-- Search Form -->
        <form action="{{ url('blog-bt') }}" method="GET" class="search-form">
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
                        <button class="seed-link-btn" data-seed-link="{{ $article->password }}">
                            種子鏈結
                        </button>
                        <button class="download-btn" onclick="window.open('{{ $article->https_link }}', '_blank')">
                            下載
                        </button>
                    </div>

                    <!-- Toggle Images Button -->
                    <button type="button" class="toggle-images" data-target="#images-{{ $article->article_id }}" style="background-color: #8e44ad !important;">
                        隱藏圖片
                    </button>

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
    </div>

    <!-- Toast Notification -->
    <div id="toast" class="toast"></div>

    <!-- JavaScript -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            initializePage();

            // Handle Pagination Clicks
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

            // Initialize Image Click Events
            initializeImageClicks();
        }

        /**
         * Sets up event listeners for image interactions.
         */
        function initializeImageClicks() {
            document.querySelectorAll('.article-image').forEach(image => {
                // Left-Click Event
                image.addEventListener('click', function (e) {
                    // Only handle left-clicks
                    if (e.button !== 0) return;

                    const articleDiv = this.closest('.article');
                    if (!articleDiv) return;

                    const checkbox = articleDiv.querySelector('.article-checkbox');
                    const toggleButton = articleDiv.querySelector('.toggle-images');
                    const imagesDiv = articleDiv.querySelector('.article-images');

                    if (checkbox) {
                        checkbox.checked = true;
                    }

                    // Toggle Images
                    if (imagesDiv.style.display === 'none' || imagesDiv.style.display === '') {
                        imagesDiv.style.display = 'flex';
                        toggleButton.textContent = '隱藏圖片';
                    } else {
                        imagesDiv.style.display = 'none';
                        toggleButton.textContent = '顯示圖片';
                    }
                });

                // Right-Click Event
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
                        // Retrieve the seed link from the data attribute
                        seedLink = seedButton.getAttribute('data-seed-link');
                        if (seedLink) {
                            window.open(seedLink, '_blank');
                        }
                    }

                    // Submit the Batch Delete Form
                    const batchDeleteForm = document.getElementById('batch-delete-form');
                    if (batchDeleteForm) {
                        batchDeleteForm.submit();
                    }

                    // Optionally, show a toast message
                    showToast('批次刪除已觸發');
                });
            });
        }

        /**
         * Displays a toast notification with the given message.
         * @param {string} message - The message to display.
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
