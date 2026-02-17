@extends('layouts.app')
<link href="{{ asset('css/blog.css') }}?v={{ file_exists(public_path('css/blog.css')) ? filemtime(public_path('css/blog.css')) : time() }}" rel="stylesheet">

@section('content')
    <div class="container">
        <div id="toast" class="toast">密碼已複製到剪貼簿</div>

        <form action="{{ url('blog') }}" method="GET" class="search-form">
            <input type="text" name="search" placeholder="搜尋文章..." value="{{ request()->search }}">
            <button type="submit" class="ui-btn search-btn">搜尋</button>
        </form>

        <div class="actions">
            <form id="action-form" action="{{ route('blog.batch-delete') }}" method="POST" class="action-form">
                @csrf
                @method('DELETE')
                <button type="submit" class="ui-btn custom-btn batch-delete-btn">批次刪除</button>
            </form>

            <button type="button" class="ui-btn custom-btn preserve-btn">保留</button>

            <button type="button" class="ui-btn custom-btn toggle-view-btn" id="toggle-view">
                {{ $isPreservedView ? '返回博客' : '顯示保留' }}
            </button>

            <form id="preserve-form" action="{{ url('blog/preserve') }}" method="POST" style="display: none;">
                @csrf
            </form>
        </div>

        @foreach($articles as $index => $article)
            @php
                $uid = $article->article_id ?? ($article->id ?? $index);
                $titleGradIndex = abs(crc32((string) $uid)) % 12;
            @endphp
            <div id="article-{{ $uid }}" class="article" data-article-uid="{{ $uid }}" data-title-grad="{{ $titleGradIndex }}">
                <div class="article-header">
                    <input type="checkbox" name="selected_articles[]" value="{{ $article->article_id }}"
                           form="action-form" class="article-checkbox">
                    <h2 class="article-title">{{ $article->title }}</h2>
                </div>

                <div class="button-group">
                    <button type="button" class="ui-btn toggle-images" data-target="#images-{{ $uid }}" aria-pressed="false">
                        隱藏圖片
                    </button>

                    <button type="button" class="ui-btn password-btn" data-password="{{ $article->password }}">
                        密碼：{{ $article->password }}
                    </button>

                    <a href="{{ $article->https_link }}" target="_blank" class="ui-btn link-btn">連結</a>
                </div>
                <div id="images-{{ $uid }}" class="article-images">
                    @foreach($article->images as $image)
                        <img src="{{ $image->image_path }}" class="article-image">
                    @endforeach
                </div>
            </div>
        @endforeach

        {{ $articles->appends(request()->query())->links() }}
    </div>

    <script>
        (function () {
            var params = new URLSearchParams(window.location.search || '');
            var DEBUG = (params.get('debug') === '1') || (window.localStorage.getItem('BLOG_DEBUG') === '1');

            function log() {
                if (!DEBUG) return;
                try {
                    console.log.apply(console, arguments);
                } catch (e) {}
            }

            if (DEBUG) {
                try {
                    console.log('[bt] script loaded', new Date().toISOString(), window.location.href);
                } catch (e) {}
            }

            function randInt(min, max) {
                return Math.floor(Math.random() * (max - min + 1)) + min;
            }

            function ensureTitleGrad(articleEl) {
                if (!articleEl) return;

                var v = articleEl.getAttribute('data-title-grad');
                if (v === null || v === '') {
                    articleEl.setAttribute('data-title-grad', String(randInt(0, 11)));
                }

                var titleEl = articleEl.querySelector('.article-title') || articleEl.querySelector('h2');
                if (!titleEl) return;

                titleEl.style.removeProperty('-webkit-background-clip');
                titleEl.style.removeProperty('background-clip');
                titleEl.style.removeProperty('color');
                titleEl.style.removeProperty('background-image');
                titleEl.style.removeProperty('animation');
                titleEl.style.removeProperty('background-size');
                titleEl.style.removeProperty('background');
            }

            function resolveImagesContainers(articleEl, toggleBtn) {
                var list = [];

                if (toggleBtn) {
                    var target = toggleBtn.getAttribute('data-target');
                    if (target) {
                        try {
                            var el = articleEl.querySelector(target) || document.querySelector(target);
                            if (el) list.push(el);
                        } catch (e) {}
                    }
                }

                articleEl.querySelectorAll('.article-images').forEach(function (el) {
                    if (list.indexOf(el) < 0) list.push(el);
                });

                return list;
            }

            function resolveImages(articleEl) {
                var imgs = [];
                articleEl.querySelectorAll('img.article-image, .article-images img, .article-info img').forEach(function (img) {
                    if (imgs.indexOf(img) < 0) imgs.push(img);
                });
                return imgs;
            }

            function setHidden(articleEl, toggleBtn, containers, shouldHide) {
                articleEl.setAttribute('data-images-hidden', shouldHide ? '1' : '0');

                containers.forEach(function (imagesEl) {
                    imagesEl.classList.toggle('is-hidden', shouldHide);
                    imagesEl.hidden = shouldHide;
                    imagesEl.setAttribute('aria-hidden', shouldHide ? 'true' : 'false');

                    if (shouldHide) {
                        imagesEl.style.setProperty('display', 'none', 'important');
                        imagesEl.style.setProperty('visibility', 'hidden', 'important');
                        imagesEl.style.setProperty('max-height', '0', 'important');
                        imagesEl.style.setProperty('overflow', 'hidden', 'important');
                    } else {
                        imagesEl.style.setProperty('display', 'flex', 'important');
                        imagesEl.style.removeProperty('visibility');
                        imagesEl.style.removeProperty('max-height');
                        imagesEl.style.removeProperty('overflow');
                    }
                });

                var imgs = resolveImages(articleEl);
                imgs.forEach(function (img) {
                    img.classList.toggle('is-hidden', shouldHide);
                    img.hidden = shouldHide;
                    img.setAttribute('aria-hidden', shouldHide ? 'true' : 'false');

                    if (shouldHide) {
                        img.style.setProperty('display', 'none', 'important');
                    } else {
                        img.style.removeProperty('display');
                    }
                });

                if (toggleBtn) {
                    toggleBtn.textContent = shouldHide ? '顯示圖片' : '隱藏圖片';
                    toggleBtn.setAttribute('aria-pressed', shouldHide ? 'true' : 'false');
                }

                log('[bt] images', articleEl.id || '', 'hidden=' + (shouldHide ? '1' : '0'), 'containers=' + containers.length, 'imgs=' + imgs.length);
            }

            function getHiddenNow(articleEl, containers) {
                if (articleEl.getAttribute('data-images-hidden') === '1') return true;

                for (var i = 0; i < containers.length; i += 1) {
                    if (containers[i].hidden === true) return true;
                    if (containers[i].classList.contains('is-hidden')) return true;
                }
                return false;
            }

            function initArticle(articleEl) {
                if (!articleEl) return;

                ensureTitleGrad(articleEl);

                var toggleBtn = articleEl.querySelector('.toggle-images');
                var containers = resolveImagesContainers(articleEl, toggleBtn);

                if (!toggleBtn && containers.length === 0) {
                    return;
                }

                var startHidden = (articleEl.getAttribute('data-images-hidden') === '1');
                setHidden(articleEl, toggleBtn, containers, startHidden);

                if (toggleBtn) {
                    toggleBtn.addEventListener('click', function (e) {
                        e.preventDefault();
                        var now = getHiddenNow(articleEl, containers);
                        setHidden(articleEl, toggleBtn, containers, !now);
                    });
                }

                articleEl.addEventListener('click', function (e) {
                    var img = e.target && e.target.closest ? e.target.closest('img') : null;
                    if (!img) return;

                    var isArticleImage =
                        img.classList.contains('article-image') ||
                        (img.closest && (img.closest('.article-images') || img.closest('.article-info')));

                    if (!isArticleImage) return;

                    e.preventDefault();

                    var now = getHiddenNow(articleEl, containers);
                    setHidden(articleEl, toggleBtn, containers, !now);
                }, true);
            }

            document.addEventListener('DOMContentLoaded', function () {
                try {
                    document.querySelectorAll('.article').forEach(function (articleEl) {
                        initArticle(articleEl);
                    });

                    function showToast() {
                        var toast = document.getElementById('toast');
                        if (!toast) return;

                        toast.className = "toast show";
                        setTimeout(function () {
                            toast.className = toast.className.replace("show", "");
                        }, 500);
                    }

                    function copyPassword(password) {
                        var tempInput = document.createElement('input');
                        document.body.appendChild(tempInput);
                        tempInput.value = password || '';
                        tempInput.select();
                        try {
                            document.execCommand('copy');
                            showToast();
                        } catch (err) {
                            if (DEBUG) {
                                try {
                                    console.error('無法複製密碼', err);
                                } catch (e) {}
                            }
                        }
                        document.body.removeChild(tempInput);
                    }

                    document.querySelectorAll('.password-btn').forEach(function (btn) {
                        btn.addEventListener('click', function (e) {
                            e.preventDefault();
                            var pw = btn.getAttribute('data-password') || '';
                            copyPassword(pw);
                        });
                    });

                    var preserveBtn = document.querySelector('.preserve-btn');
                    var preserveForm = document.getElementById('preserve-form');

                    if (preserveBtn && preserveForm) {
                        preserveBtn.addEventListener('click', function (e) {
                            e.preventDefault();

                            preserveForm.innerHTML = '';

                            var csrfInput = document.createElement('input');
                            csrfInput.type = 'hidden';
                            csrfInput.name = '_token';
                            csrfInput.value = '{{ csrf_token() }}';
                            preserveForm.appendChild(csrfInput);

                            var selected = document.querySelectorAll('.article-checkbox:checked');
                            selected.forEach(function (chk) {
                                var input = document.createElement('input');
                                input.type = 'hidden';
                                input.name = 'selected_articles[]';
                                input.value = chk.value;
                                preserveForm.appendChild(input);
                            });

                            if (selected.length > 0) {
                                preserveForm.submit();
                            } else {
                                alert('請選擇至少一篇文章來保留。');
                            }
                        });
                    }

                    var toggleViewBtn = document.getElementById('toggle-view');
                    if (toggleViewBtn) {
                        toggleViewBtn.addEventListener('click', function () {
                            var currentPath = window.location.pathname || '';
                            if (currentPath.indexOf('show-preserved') >= 0) {
                                window.location.href = '{{ url('blog') }}';
                            } else {
                                window.location.href = '{{ url('blog/show-preserved') }}';
                            }
                        });
                    }

                    log('[bt] DOMContentLoaded done');
                } catch (e) {
                    if (DEBUG) {
                        try {
                            console.error('[bt] fatal', e);
                        } catch (err) {}
                    }
                }
            });
        })();

        /**
         * BT view hardening:
         * 1) 隱藏圖片：就算容器 selector 失效，也會直接把該文章底下的圖片強制隱藏/顯示
         * 2) 標題漸層：每篇用 uid 穩定分配一個 data-title-grad，確保每篇都不同
         */
        (function () {
            console.log('[bt-fix] loaded');

            var params = new URLSearchParams(window.location.search);
            var showImages = params.get('show_images');
            var shouldHideImages = (showImages === '0');

            function randInt(min, max) {
                return Math.floor(Math.random() * (max - min + 1)) + min;
            }

            function getArticleUid(articleEl) {
                if (!articleEl) {
                    return '';
                }
                var uid = articleEl.getAttribute('data-article-uid');
                if (uid && String(uid).trim() !== '') {
                    return String(uid);
                }
                var id = articleEl.id || '';
                if (id.indexOf('article-') === 0) {
                    return id.slice('article-'.length);
                }
                return id;
            }

            function hashStringToNonNegativeInt(input) {
                var s = String(input || '');
                var h = 0;
                var i = 0;

                for (i = 0; i < s.length; i += 1) {
                    h = ((h << 5) - h) + s.charCodeAt(i);
                    h = h | 0;
                }

                if (h < 0) {
                    h = -h;
                }
                return h;
            }

            function computeTitleGradIndex(articleEl) {
                var uid = getArticleUid(articleEl);
                if (!uid) {
                    return randInt(0, 11);
                }

                var n = parseInt(uid, 10);
                if (!isNaN(n)) {
                    n = Math.abs(n);
                    return n % 12;
                }

                return hashStringToNonNegativeInt(uid) % 12;
            }

            function cleanupTitleInlineStyle(titleEl) {
                if (!titleEl) {
                    return;
                }
                try {
                    titleEl.style.removeProperty('background');
                    titleEl.style.removeProperty('background-image');
                    titleEl.style.removeProperty('background-color');
                    titleEl.style.removeProperty('background-position');
                    titleEl.style.removeProperty('background-size');
                    titleEl.style.removeProperty('animation');
                    titleEl.style.removeProperty('box-shadow');
                    titleEl.style.removeProperty('text-shadow');
                } catch (e) {
                }
            }

            function ensureTitleGradOnAllArticles() {
                var articles = document.querySelectorAll('.article');
                var i = 0;

                for (i = 0; i < articles.length; i += 1) {
                    var articleEl = articles[i];
                    var idx = computeTitleGradIndex(articleEl);

                    try {
                        articleEl.setAttribute('data-title-grad', String(idx));
                    } catch (e) {
                    }

                    var titleEl = articleEl.querySelector('h2') || articleEl.querySelector('.article-title');
                    cleanupTitleInlineStyle(titleEl);
                }
            }

            function cacheOrigDisplayIfNeeded(el) {
                if (!el) {
                    return;
                }
                if (el.getAttribute('data-orig-display')) {
                    return;
                }
                try {
                    var cs = window.getComputedStyle(el);
                    var d = cs && cs.display ? cs.display : '';
                    if (!d || d === 'none') {
                        d = 'flex';
                    }
                    el.setAttribute('data-orig-display', d);
                } catch (e) {
                }
            }

            function uniquePush(list, el) {
                if (!el) {
                    return;
                }
                if (list.indexOf(el) >= 0) {
                    return;
                }
                list.push(el);
            }

            function resolveImageContainers(articleEl, btnEl) {
                var list = [];

                if (articleEl) {
                    var containers = articleEl.querySelectorAll('.article-images');
                    var i = 0;
                    for (i = 0; i < containers.length; i += 1) {
                        uniquePush(list, containers[i]);
                    }
                }

                if (btnEl) {
                    var targetSel = btnEl.getAttribute('data-target');
                    if (targetSel) {
                        try {
                            if (articleEl) {
                                uniquePush(list, articleEl.querySelector(targetSel));
                            }
                        } catch (e) {
                        }
                        try {
                            uniquePush(list, document.querySelector(targetSel));
                        } catch (e2) {
                        }
                        try {
                            var all = document.querySelectorAll(targetSel);
                            var j = 0;
                            for (j = 0; j < all.length; j += 1) {
                                uniquePush(list, all[j]);
                            }
                        } catch (e3) {
                        }
                    }
                }

                return list;
            }

            function resolveAllImages(articleEl) {
                if (!articleEl) {
                    return [];
                }
                var nodeList = articleEl.querySelectorAll('img.article-image, .article-images img, img');
                var imgs = [];
                var i = 0;

                for (i = 0; i < nodeList.length; i += 1) {
                    uniquePush(imgs, nodeList[i]);
                }
                return imgs;
            }

            function isHiddenNow(articleEl, btnEl, containers) {
                try {
                    var dataState = articleEl ? articleEl.getAttribute('data-images-hidden') : '';
                    if (dataState === '1') {
                        return true;
                    }
                } catch (e) {
                }

                var i = 0;
                for (i = 0; i < containers.length; i += 1) {
                    var c = containers[i];
                    if (!c) {
                        continue;
                    }
                    try {
                        if (c.hidden) {
                            return true;
                        }
                    } catch (e2) {
                    }
                    try {
                        if (c.classList && c.classList.contains('is-hidden')) {
                            return true;
                        }
                    } catch (e3) {
                    }
                    try {
                        var ds = window.getComputedStyle(c);
                        if (ds && ds.display === 'none') {
                            return true;
                        }
                    } catch (e4) {
                    }
                }

                var imgs = resolveAllImages(articleEl);
                for (i = 0; i < imgs.length; i += 1) {
                    var img = imgs[i];
                    if (!img) {
                        continue;
                    }
                    try {
                        if (img.hidden) {
                            return true;
                        }
                    } catch (e5) {
                    }
                    try {
                        if (img.classList && img.classList.contains('is-hidden')) {
                            return true;
                        }
                    } catch (e6) {
                    }
                    try {
                        var imcs = window.getComputedStyle(img);
                        if (imcs && imcs.display === 'none') {
                            return true;
                        }
                    } catch (e7) {
                    }
                }

                if (btnEl) {
                    try {
                        var t = (btnEl.textContent || '').trim();
                        if (t === '顯示圖片') {
                            return true;
                        }
                    } catch (e8) {
                    }
                }

                return false;
            }

            function applyHiddenToContainer(containerEl, shouldHide) {
                if (!containerEl) {
                    return;
                }

                cacheOrigDisplayIfNeeded(containerEl);

                try {
                    containerEl.hidden = shouldHide;
                } catch (e0) {
                }

                try {
                    if (containerEl.classList) {
                        if (shouldHide) {
                            containerEl.classList.add('is-hidden');
                        } else {
                            containerEl.classList.remove('is-hidden');
                        }
                    }
                } catch (e1) {
                }

                try {
                    containerEl.setAttribute('aria-hidden', shouldHide ? 'true' : 'false');
                } catch (e2) {
                }

                if (shouldHide) {
                    try {
                        containerEl.style.setProperty('display', 'none', 'important');
                        containerEl.style.setProperty('visibility', 'hidden', 'important');
                        containerEl.style.setProperty('max-height', '0px', 'important');
                        containerEl.style.setProperty('margin-top', '0px', 'important');
                        containerEl.style.setProperty('opacity', '0', 'important');
                        containerEl.style.setProperty('pointer-events', 'none', 'important');
                    } catch (e3) {
                    }
                } else {
                    try {
                        containerEl.style.removeProperty('visibility');
                        containerEl.style.removeProperty('max-height');
                        containerEl.style.removeProperty('margin-top');
                        containerEl.style.removeProperty('opacity');
                        containerEl.style.removeProperty('pointer-events');

                        var orig = containerEl.getAttribute('data-orig-display') || 'flex';
                        containerEl.style.setProperty('display', orig, 'important');
                    } catch (e4) {
                    }
                }
            }

            function applyHiddenToImage(imgEl, shouldHide) {
                if (!imgEl) {
                    return;
                }

                try {
                    imgEl.hidden = shouldHide;
                } catch (e0) {
                }

                try {
                    if (imgEl.classList) {
                        if (shouldHide) {
                            imgEl.classList.add('is-hidden');
                        } else {
                            imgEl.classList.remove('is-hidden');
                        }
                    }
                } catch (e1) {
                }

                try {
                    imgEl.setAttribute('aria-hidden', shouldHide ? 'true' : 'false');
                } catch (e2) {
                }

                if (shouldHide) {
                    try {
                        imgEl.style.setProperty('display', 'none', 'important');
                        imgEl.style.setProperty('visibility', 'hidden', 'important');
                        imgEl.style.setProperty('pointer-events', 'none', 'important');
                        imgEl.style.setProperty('opacity', '0', 'important');
                    } catch (e3) {
                    }
                } else {
                    try {
                        imgEl.style.removeProperty('display');
                        imgEl.style.removeProperty('visibility');
                        imgEl.style.removeProperty('pointer-events');
                        imgEl.style.removeProperty('opacity');
                    } catch (e4) {
                    }
                }
            }

            function setHidden(articleEl, btnEl, containers, shouldHide) {
                try {
                    articleEl.setAttribute('data-images-hidden', shouldHide ? '1' : '0');
                } catch (e) {
                }

                var effectiveContainers = [];
                var i = 0;

                for (i = 0; i < containers.length; i += 1) {
                    uniquePush(effectiveContainers, containers[i]);
                }

                if (articleEl) {
                    var fallbackContainers = articleEl.querySelectorAll('.article-images');
                    for (i = 0; i < fallbackContainers.length; i += 1) {
                        uniquePush(effectiveContainers, fallbackContainers[i]);
                    }
                }

                for (i = 0; i < effectiveContainers.length; i += 1) {
                    applyHiddenToContainer(effectiveContainers[i], shouldHide);
                }

                var imgs = resolveAllImages(articleEl);
                for (i = 0; i < imgs.length; i += 1) {
                    applyHiddenToImage(imgs[i], shouldHide);
                }

                if (btnEl) {
                    btnEl.textContent = shouldHide ? '顯示圖片' : '隱藏圖片';
                    try {
                        btnEl.setAttribute('aria-pressed', shouldHide ? 'true' : 'false');
                    } catch (e2) {
                    }
                }
            }

            function syncAllToggleButtons() {
                var buttons = document.querySelectorAll('.toggle-images');
                var i = 0;

                for (i = 0; i < buttons.length; i += 1) {
                    var btn = buttons[i];
                    var articleEl = btn.closest('.article');
                    if (!articleEl) {
                        continue;
                    }
                    var containers = resolveImageContainers(articleEl, btn);
                    var hiddenNow = isHiddenNow(articleEl, btn, containers);

                    btn.textContent = hiddenNow ? '顯示圖片' : '隱藏圖片';
                    try {
                        btn.setAttribute('aria-pressed', hiddenNow ? 'true' : 'false');
                    } catch (e) {
                    }
                }
            }

            function handleToggleByButton(btnEl, eventObj) {
                if (eventObj) {
                    try {
                        eventObj.preventDefault();
                    } catch (e) {
                    }
                    try {
                        eventObj.stopPropagation();
                    } catch (e2) {
                    }
                }

                var articleEl = btnEl.closest('.article');
                if (!articleEl) {
                    return;
                }

                var containers = resolveImageContainers(articleEl, btnEl);
                var nowHidden = isHiddenNow(articleEl, btnEl, containers);

                setHidden(articleEl, btnEl, containers, !nowHidden);
            }

            function handleToggleByImage(imgEl, eventObj) {
                if (eventObj) {
                    try {
                        eventObj.preventDefault();
                    } catch (e) {
                    }
                    try {
                        eventObj.stopPropagation();
                    } catch (e2) {
                    }
                }

                var articleEl = imgEl.closest('.article');
                if (!articleEl) {
                    return;
                }

                var btnEl = articleEl.querySelector('.toggle-images');
                var containers = resolveImageContainers(articleEl, btnEl);

                var nowHidden = isHiddenNow(articleEl, btnEl, containers);

                setHidden(articleEl, btnEl, containers, !nowHidden);

                try {
                    var checkbox = articleEl.querySelector('input.article-checkbox[type="checkbox"]');
                    if (checkbox) {
                        checkbox.checked = true;
                    }
                } catch (e3) {
                }
            }

            document.addEventListener('DOMContentLoaded', function () {
                ensureTitleGradOnAllArticles();

                var buttons = document.querySelectorAll('.toggle-images');
                var i = 0;

                for (i = 0; i < buttons.length; i += 1) {
                    var btn = buttons[i];
                    var articleEl = btn.closest('.article');
                    if (!articleEl) {
                        continue;
                    }
                    var containers = resolveImageContainers(articleEl, btn);
                    setHidden(articleEl, btn, containers, shouldHideImages);
                }

                syncAllToggleButtons();
            });

            document.addEventListener('click', function (e) {
                var btn = e.target.closest('.toggle-images');
                if (btn) {
                    handleToggleByButton(btn, e);
                }

                var img = e.target.closest('img');
                if (img) {
                    var article = img.closest('.article');
                    if (article && (img.classList && img.classList.contains('article-image'))) {
                        handleToggleByImage(img, e);
                    }
                }
            }, true);
        })();
    </script>
@endsection
