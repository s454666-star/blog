<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <title>BTDig Results</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gradient-to-br from-slate-50 via-sky-50 to-indigo-50 min-h-screen text-slate-900">
<div class="container mx-auto px-6 py-10">
    @php
        /** @var \Illuminate\Pagination\AbstractPaginator|\Illuminate\Support\Collection $results */
    @endphp

    <h1 class="text-4xl font-bold mb-8 text-center tracking-widest text-sky-700 drop-shadow-sm">
        🎬 Magnet List
    </h1>

    <form id="filterForm" action="{{ route('btdig.index') }}" method="GET"
          class="mb-8 bg-white/70 backdrop-blur border border-slate-200 rounded-2xl p-5 shadow-sm">
        <div class="flex flex-wrap gap-4 items-end justify-between">
            <div class="flex flex-wrap gap-4 items-end">
                <div>
                    <label for="type" class="block text-sm font-semibold text-slate-700 mb-1">Type</label>
                    <select name="type" id="type"
                            class="px-4 py-2 rounded-xl border border-slate-200 bg-white/80 shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-300">
                        <option value="2" {{ ($type ?? '2') === '2' ? 'selected' : '' }}>type:2</option>
                        <option value="1" {{ ($type ?? '2') === '1' ? 'selected' : '' }}>type:1</option>
                        <option value="all" {{ ($type ?? '2') === 'all' ? 'selected' : '' }}>all</option>
                    </select>
                </div>

                <div>
                    <label for="keyword_from" class="block text-sm font-semibold text-slate-700 mb-1">關鍵字起</label>
                    <input type="number" step="1" min="0" name="keyword_from" id="keyword_from"
                           value="{{ $keywordFrom ?? '' }}"
                           placeholder="例如：900 或 1247868"
                           class="w-56 px-4 py-2 rounded-xl border border-slate-200 bg-white/80 shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-300">
                </div>

                <div>
                    <label for="keyword_to" class="block text-sm font-semibold text-slate-700 mb-1">關鍵字迄</label>
                    <input type="number" step="1" min="0" name="keyword_to" id="keyword_to"
                           value="{{ $keywordTo ?? '' }}"
                           placeholder="例如：902 或 1247975"
                           class="w-56 px-4 py-2 rounded-xl border border-slate-200 bg-white/80 shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-300">
                </div>

                <div>
                    <label for="keyword_sort" class="block text-sm font-semibold text-slate-700 mb-1">關鍵字排序</label>
                    <select name="keyword_sort" id="keyword_sort"
                            class="w-56 px-4 py-2 rounded-xl border border-slate-200 bg-white/80 shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-300">
                        <option value="asc" {{ ($keywordSortDir ?? 'asc') === 'asc' ? 'selected' : '' }}>低 → 高</option>
                        <option value="desc" {{ ($keywordSortDir ?? 'asc') === 'desc' ? 'selected' : '' }}>高 → 低</option>
                    </select>
                </div>

                <div>
                    <label for="per_page" class="block text-sm font-semibold text-slate-700 mb-1">每頁筆數</label>
                    <select name="per_page" id="per_page"
                            class="w-44 px-4 py-2 rounded-xl border border-slate-200 bg-white/80 shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-300">
                        <option value="100" {{ ((int)($perPage ?? 400)) === 100 ? 'selected' : '' }}>100</option>
                        <option value="200" {{ ((int)($perPage ?? 400)) === 200 ? 'selected' : '' }}>200</option>
                        <option value="400" {{ ((int)($perPage ?? 400)) === 400 ? 'selected' : '' }}>400</option>
                        <option value="600" {{ ((int)($perPage ?? 400)) === 600 ? 'selected' : '' }}>600</option>
                    </select>
                </div>

                <div class="flex items-center gap-2 h-[42px] px-4 py-2 rounded-xl border border-slate-200 bg-white/80 shadow-sm">
                    <input type="checkbox" name="hide_disabled_groups" id="hide_disabled_groups" value="1"
                           {{ !empty($hideDisabledGroups) ? 'checked' : '' }}
                           class="w-4 h-4 accent-sky-600">
                    <label for="hide_disabled_groups" class="text-sm font-semibold text-slate-700 select-none">
                        隱藏含已複製的群組
                    </label>
                </div>

                <button type="submit"
                        class="bg-sky-600 hover:bg-sky-500 text-white px-6 py-2 rounded-xl shadow-lg transform hover:scale-105 transition duration-300">
                    🔍 篩選
                </button>

                <a href="{{ route('btdig.index') }}"
                   class="bg-slate-500 hover:bg-slate-400 text-white px-6 py-2 rounded-xl shadow-lg transform hover:scale-105 transition duration-300">
                    🧹 清除
                </a>
            </div>

        </div>
    </form>

    @php
        $collection = $results instanceof \Illuminate\Pagination\AbstractPaginator ? collect($results->items()) : collect($results);
        $grouped = $collection->groupBy('search_keyword');
        $hideDisabled = !empty($hideDisabledGroups);
        $visibleGrouped = $grouped->filter(function ($items) use ($hideDisabled) {
            $groupHasDisabled = $items->contains(function ($row) {
                return !empty($row->copied_at);
            });

            return !$hideDisabled || !$groupHasDisabled;
        });
        $pageResultsCount = $visibleGrouped->sum(function ($items) {
            return $items->count();
        });
        $allResultsCount = $results instanceof \Illuminate\Pagination\AbstractPaginator
            ? $results->total()
            : $collection->count();
    @endphp

    <div class="flex flex-wrap gap-4 justify-between items-center mb-8">
        <div class="text-lg text-slate-700">
            當頁總筆數：
            <span class="font-bold text-sky-700">{{ $pageResultsCount }}</span>
            筆
            <span class="mx-2 text-slate-300">|</span>
            全部總筆數：
            <span class="font-bold text-violet-700">{{ $allResultsCount }}</span>
            筆
            <span class="mx-2 text-slate-300">|</span>
            已選擇：
            <span id="selectedCount" class="font-bold text-emerald-600">0</span>
            筆
        </div>

        <div class="flex gap-3 items-center">
            <button type="button" onclick="toggleAllSelectable()"
                    class="bg-violet-600 hover:bg-violet-500 text-white px-6 py-2 rounded-lg shadow-lg transform hover:scale-105 transition duration-300">
                ✅ 全選/全不選
            </button>

            <button type="button" onclick="copySelected()"
                    class="bg-emerald-500 hover:bg-emerald-400 text-white px-6 py-2 rounded-lg shadow-lg transform hover:scale-105 transition duration-300">
                📋 複製 Magnet
            </button>
        </div>
    </div>

    @forelse($visibleGrouped as $keyword => $items)
        @php
            $groupHasDisabled = $items->contains(function ($row) {
                return !empty($row->copied_at);
            });

            $groupOuterClass = $groupHasDisabled
                ? 'bg-emerald-50/60 border-emerald-200 shadow-emerald-200/30'
                : 'bg-white/70 border-slate-200 shadow-slate-200/40';

            $groupHeaderBarClass = $groupHasDisabled
                ? 'border-emerald-400 text-emerald-700'
                : 'border-sky-500 text-sky-700';

            $groupHintBadgeClass = $groupHasDisabled
                ? 'bg-emerald-100 text-emerald-700 border-emerald-200'
                : 'bg-sky-100 text-sky-700 border-sky-200';
        @endphp

        <div class="mb-12 rounded-3xl border backdrop-blur p-6 shadow-lg {{ $groupOuterClass }}">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
                <h2 class="text-2xl font-bold border-l-4 pl-4 {{ $groupHeaderBarClass }}">
                    <span>🔎 關鍵字：</span>
                    <button type="button"
                            class="inline-flex items-center rounded-lg px-2 py-1 transition hover:bg-white/70 hover:text-sky-600 focus:outline-none focus:ring-2 focus:ring-sky-300"
                            onclick="copyKeyword(event, '{{ e($keyword) }}')">
                        {{ $keyword }}
                    </button>
                </h2>

                <div class="flex items-center gap-2">
                    @if($groupHasDisabled)
                        <span class="text-xs font-semibold px-3 py-1 rounded-full border {{ $groupHintBadgeClass }}">
                            群組含已複製
                        </span>
                    @else
                        <span class="text-xs font-semibold px-3 py-1 rounded-full border {{ $groupHintBadgeClass }}">
                            群組未複製
                        </span>
                    @endif
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-5 gap-6">
                @foreach($items as $row)
                    @php
                        $isCopied = !empty($row->copied_at);
                        $magnet = (string)($row->magnet ?? '');
                        $name = (string)($row->name ?? '');
                    @endphp

                    <div
                        class="card flex flex-col bg-white/85 backdrop-blur border border-slate-200 rounded-2xl p-5 shadow-md transition duration-300
                               hover:-translate-y-1 hover:shadow-sky-500/15 hover:shadow-xl"
                        data-id="{{ $row->id }}"
                        data-magnet="{{ e($magnet) }}"
                        data-copied="{{ $isCopied ? '1' : '0' }}"
                        style="{{ $isCopied ? 'opacity:0.58; filter:grayscale(0.15); cursor:not-allowed;' : 'cursor:pointer;' }}"
                    >
                        <input type="checkbox"
                               class="hidden magnetCheckbox"
                               value="{{ e($magnet) }}"
                               data-id="{{ $row->id }}"
                            {{ $isCopied ? 'disabled' : '' }}>

                        <div class="flex items-start justify-between gap-3">
                            <h3 class="text-base font-semibold text-emerald-700 break-words leading-snug">
                                {{ $name }}
                            </h3>

                            <div class="flex flex-col items-end gap-2 shrink-0">
                                <span class="text-xs font-semibold px-3 py-1 rounded-full bg-sky-100 text-sky-700 border border-sky-200">
                                    type:{{ $row->type ?? '' }}
                                </span>

                                @if($isCopied)
                                    <span class="text-xs font-semibold px-3 py-1 rounded-full bg-emerald-100 text-emerald-700 border border-emerald-200 copied-badge">
                                        已複製
                                    </span>
                                @endif
                            </div>
                        </div>

                        <div class="mt-3 text-sm space-y-2 text-slate-600">
                            <div class="flex items-center gap-2">
                                <span class="text-slate-500">💾</span>
                                <span>容量：{{ $row->size ?? '' }}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="text-slate-500">📂</span>
                                <span>檔案數：{{ $row->files ?? '' }}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="text-slate-500">⏳</span>
                                <span>年齡：{{ $row->age ?? '' }}</span>
                            </div>
                        </div>

                        <div class="mt-4 flex-1">
                            <div class="text-xs text-slate-500 break-all leading-relaxed bg-slate-50/70 border border-slate-100 rounded-xl p-3">
                                {{ \Illuminate\Support\Str::limit($magnet, 120) }}
                            </div>
                        </div>

                        <div class="mt-auto pt-4 flex gap-2">
                            <button type="button"
                                    class="w-1/2 px-4 py-2.5 rounded-xl text-white shadow-sm transition duration-300 transform hover:scale-105
                                           {{ $isCopied ? 'bg-slate-400 cursor-not-allowed' : 'bg-emerald-600 hover:bg-emerald-500' }}"
                                    onclick="copyOne(event, {{ (int)$row->id }})"
                                {{ $isCopied ? 'disabled' : '' }}>
                                複製
                            </button>

                            <button type="button"
                                    class="w-1/2 px-4 py-2.5 rounded-xl text-white shadow-sm transition duration-300 transform hover:scale-105 bg-sky-600 hover:bg-sky-500"
                                    onclick="openMagnet(event, '{{ e($magnet) }}')"
                                {{ empty($magnet) ? 'disabled' : '' }}>
                                開 Magnet
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <div class="bg-white/70 backdrop-blur border border-slate-200 rounded-2xl p-8 shadow-sm text-center text-slate-600">
            沒有資料
        </div>
    @endforelse

    @if($results instanceof \Illuminate\Pagination\AbstractPaginator && $results->hasPages())
        @php
            $current = $results->currentPage();
            $last = $results->lastPage();
            $start = max(1, $current - 3);
            $end = min($last, $current + 3);

            if (($end - $start) < 6) {
                $need = 6 - ($end - $start);
                $start = max(1, $start - $need);
                $end = min($last, $end + $need);
            }

            $firstItem = $results->firstItem();
            $lastItem = $results->lastItem();
            $total = $results->total();
        @endphp

        <div class="mt-10">
            <div class="bg-white/70 backdrop-blur border border-slate-200 rounded-2xl p-4 shadow-sm">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                    <div class="text-sm text-slate-600">
                        @if($firstItem !== null && $lastItem !== null)
                            顯示 <span class="font-semibold text-slate-800">{{ $firstItem }}</span>
                            ～ <span class="font-semibold text-slate-800">{{ $lastItem }}</span>
                            / 共 <span class="font-semibold text-slate-800">{{ $total }}</span> 筆
                        @else
                            共 <span class="font-semibold text-slate-800">{{ $total }}</span> 筆
                        @endif
                    </div>

                    <nav class="flex flex-wrap items-center justify-center gap-2 select-none">
                        @if($results->onFirstPage())
                            <span class="px-3 py-2 rounded-xl border border-slate-200 bg-white/60 text-slate-400 cursor-not-allowed">
                                上一頁
                            </span>
                        @else
                            <a href="{{ $results->appends(request()->query())->previousPageUrl() }}"
                               class="px-3 py-2 rounded-xl border border-slate-200 bg-white/80 text-slate-700 hover:bg-white hover:shadow-sm transition">
                                上一頁
                            </a>
                        @endif

                        @if($start > 1)
                            <a href="{{ $results->appends(request()->query())->url(1) }}"
                               class="w-10 h-10 inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white/80 text-slate-700 hover:bg-white hover:shadow-sm transition">
                                1
                            </a>
                            @if($start > 2)
                                <span class="px-2 text-slate-400">…</span>
                            @endif
                        @endif

                        @for($p = $start; $p <= $end; $p++)
                            @if($p === $current)
                                <span class="w-10 h-10 inline-flex items-center justify-center rounded-xl bg-sky-600 text-white shadow-sm">
                                    {{ $p }}
                                </span>
                            @else
                                <a href="{{ $results->appends(request()->query())->url($p) }}"
                                   class="w-10 h-10 inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white/80 text-slate-700 hover:bg-white hover:shadow-sm transition">
                                    {{ $p }}
                                </a>
                            @endif
                        @endfor

                        @if($end < $last)
                            @if($end < ($last - 1))
                                <span class="px-2 text-slate-400">…</span>
                            @endif
                            <a href="{{ $results->appends(request()->query())->url($last) }}"
                               class="w-10 h-10 inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white/80 text-slate-700 hover:bg-white hover:shadow-sm transition">
                                {{ $last }}
                            </a>
                        @endif

                        @if($results->hasMorePages())
                            <a href="{{ $results->appends(request()->query())->nextPageUrl() }}"
                               class="px-3 py-2 rounded-xl border border-slate-200 bg-white/80 text-slate-700 hover:bg-white hover:shadow-sm transition">
                                下一頁
                            </a>
                        @else
                            <span class="px-3 py-2 rounded-xl border border-slate-200 bg-white/60 text-slate-400 cursor-not-allowed">
                                下一頁
                            </span>
                        @endif
                    </nav>
                </div>
            </div>
        </div>
    @endif
</div>

<div id="toast"
     class="fixed bottom-6 left-1/2 -translate-x-1/2 hidden px-6 py-3 rounded-xl shadow-2xl bg-slate-900/90 text-white text-sm">
</div>

<script>
    const selectedCountEl = document.getElementById('selectedCount');
    const toastEl = document.getElementById('toast');
    const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    const markCopiedUrl = "{{ route('btdig.markCopied') }}";

    function showToast(message) {
        toastEl.textContent = message;
        toastEl.classList.remove('hidden');
        setTimeout(() => {
            toastEl.classList.add('hidden');
        }, 2200);
    }

    function getAllCards() {
        return Array.from(document.querySelectorAll('.card'));
    }

    function updateCount() {
        const checked = document.querySelectorAll('.magnetCheckbox:checked');
        selectedCountEl.innerText = checked.length.toString();
    }

    function cardSelectable(card) {
        return card && card.getAttribute('data-copied') !== '1';
    }

    function setCardSelectedStyle(card, selected) {
        if (!card) return;

        if (selected) {
            card.classList.add('ring-4', 'ring-emerald-400', 'bg-emerald-50', 'shadow-emerald-400/20');
            card.classList.remove('bg-white/85');
        } else {
            card.classList.remove('ring-4', 'ring-emerald-400', 'bg-emerald-50', 'shadow-emerald-400/20');
            card.classList.add('bg-white/85');
        }
    }

    function wireCardClicks() {
        const cards = getAllCards();
        cards.forEach(card => {
            card.addEventListener('click', function () {
                if (!cardSelectable(this)) {
                    showToast('此項目已複製，無法再選取');
                    return;
                }

                const checkbox = this.querySelector('.magnetCheckbox');
                if (!checkbox || checkbox.disabled) return;

                checkbox.checked = !checkbox.checked;
                setCardSelectedStyle(this, checkbox.checked);
                updateCount();
            });
        });
    }

    function toggleAllSelectable() {
        const cards = getAllCards().filter(c => cardSelectable(c));
        if (cards.length === 0) {
            showToast('沒有可選取的項目');
            return;
        }

        const checkboxes = cards.map(c => c.querySelector('.magnetCheckbox')).filter(cb => cb && !cb.disabled);
        const hasUnchecked = checkboxes.some(cb => !cb.checked);

        checkboxes.forEach(cb => {
            cb.checked = hasUnchecked;
            const card = cb.closest('.card');
            setCardSelectedStyle(card, cb.checked);
        });

        updateCount();
    }

    async function copyToClipboard(text) {
        const s = (text || '').trim();
        if (!s) return false;

        if (navigator.clipboard && navigator.clipboard.writeText) {
            try {
                await navigator.clipboard.writeText(s);
                return true;
            } catch (e) {
            }
        }

        try {
            const textarea = document.createElement('textarea');
            textarea.value = s;
            textarea.setAttribute('readonly', 'readonly');
            textarea.style.position = 'fixed';
            textarea.style.left = '-9999px';
            document.body.appendChild(textarea);
            textarea.select();
            const ok = document.execCommand('copy');
            textarea.remove();
            return ok;
        } catch (e) {
            return false;
        }
    }

    async function copyKeyword(event, keyword) {
        event.preventDefault();
        event.stopPropagation();

        const value = (keyword || '').trim();
        if (!value) {
            showToast('沒有可複製的關鍵字');
            return;
        }

        const ok = await copyToClipboard(value);
        showToast(ok ? '關鍵字已複製' : '關鍵字複製失敗');
    }

    function getCurrentQueryString() {
        return window.location.search || '';
    }

    function reloadKeepQuery() {
        window.location.href = window.location.pathname + getCurrentQueryString();
    }

    async function postMarkCopied(ids) {
        const res = await fetch(markCopiedUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json'
            },
            body: JSON.stringify({ ids: ids })
        });

        if (!res.ok) {
            let msg = '標記失敗';
            try {
                const data = await res.json();
                if (data && data.message) msg = data.message;
            } catch (e) {
            }
            throw new Error(msg);
        }

        const data = await res.json();
        if (!data || data.ok !== true) {
            throw new Error('標記失敗');
        }
    }

    function setCardCopied(card) {
        if (!card) return;

        card.setAttribute('data-copied', '1');
        card.style.opacity = '0.58';
        card.style.filter = 'grayscale(0.15)';
        card.style.cursor = 'not-allowed';

        const checkbox = card.querySelector('.magnetCheckbox');
        if (checkbox) {
            checkbox.checked = false;
            checkbox.disabled = true;
        }

        setCardSelectedStyle(card, false);

        const badges = card.querySelectorAll('.copied-badge');
        if (badges.length === 0) {
            const badgeWrap = card.querySelector('div.flex.items-start.justify-between');
            if (badgeWrap) {
                const badge = document.createElement('span');
                badge.className = 'copied-badge text-xs font-semibold px-3 py-1 rounded-full bg-emerald-100 text-emerald-700 border border-emerald-200';
                badge.textContent = '已複製';
                const rightCol = badgeWrap.querySelector('div.flex.flex-col.items-end');
                if (rightCol) {
                    rightCol.appendChild(badge);
                }
            }
        }

        const btn = card.querySelector('button[onclick^="copyOne"]');
        if (btn) {
            btn.disabled = true;
            btn.classList.remove('bg-emerald-600', 'hover:bg-emerald-500');
            btn.classList.add('bg-slate-400', 'cursor-not-allowed');
        }
    }

    async function copySelected() {
        const checked = Array.from(document.querySelectorAll('.magnetCheckbox:checked')).filter(cb => !cb.disabled);
        if (checked.length === 0) {
            showToast('請先選擇項目');
            return;
        }

        const magnets = [];
        const ids = [];

        checked.forEach(cb => {
            const magnet = (cb.value || '').trim();
            const id = parseInt(cb.getAttribute('data-id') || '0', 10);
            if (magnet) magnets.push(magnet);
            if (id > 0) ids.push(id);
        });

        if (magnets.length === 0 || ids.length === 0) {
            showToast('沒有可複製的 Magnet');
            return;
        }

        const ok = await copyToClipboard(magnets.join("\n"));
        if (!ok) {
            showToast('複製失敗（可能被瀏覽器阻擋）');
            return;
        }

        try {
            await postMarkCopied(ids);
            ids.forEach(id => {
                const card = document.querySelector('.card[data-id="' + id + '"]');
                setCardCopied(card);
            });
            updateCount();
            showToast('已複製並標記，頁面更新中');
            setTimeout(reloadKeepQuery, 250);
        } catch (err) {
            showToast(err && err.message ? err.message : '標記失敗');
        }
    }

    async function copyOne(event, id) {
        event.preventDefault();
        event.stopPropagation();

        const card = document.querySelector('.card[data-id="' + id + '"]');
        if (!card || !cardSelectable(card)) {
            showToast('此項目已複製，無法再選取');
            return;
        }

        const magnet = (card.getAttribute('data-magnet') || '').trim();
        if (!magnet) {
            showToast('沒有 Magnet 可複製');
            return;
        }

        const ok = await copyToClipboard(magnet);
        if (!ok) {
            showToast('複製失敗（可能被瀏覽器阻擋）');
            return;
        }

        try {
            await postMarkCopied([id]);
            setCardCopied(card);
            updateCount();
            showToast('已複製並標記，頁面更新中');
            setTimeout(reloadKeepQuery, 250);
        } catch (err) {
            showToast(err && err.message ? err.message : '標記失敗');
        }
    }

    function openMagnet(event, magnet) {
        event.preventDefault();
        event.stopPropagation();

        const link = (magnet || '').trim();
        if (!link) {
            showToast('沒有 Magnet');
            return;
        }

        try {
            const w = window.open(link, '_blank', 'noopener');
            if (!w) {
                const a = document.createElement('a');
                a.href = link;
                a.target = '_blank';
                a.rel = 'noopener noreferrer';
                document.body.appendChild(a);
                a.click();
                a.remove();
            }
        } catch (e) {
            showToast('開啟失敗');
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        wireCardClicks();
        updateCount();

        const disabledCards = getAllCards().filter(c => c.getAttribute('data-copied') === '1');
        disabledCards.forEach(c => setCardSelectedStyle(c, false));

        const form = document.getElementById('filterForm');
        const hideToggle = document.getElementById('hide_disabled_groups');
        const keywordSort = document.getElementById('keyword_sort');
        const perPage = document.getElementById('per_page');

        if (hideToggle && form) {
            hideToggle.addEventListener('change', function () {
                form.submit();
            });
        }
        if (keywordSort && form) {
            keywordSort.addEventListener('change', function () {
                form.submit();
            });
        }
        if (perPage && form) {
            perPage.addEventListener('change', function () {
                form.submit();
            });
        }
    });
</script>

</body>
</html>
