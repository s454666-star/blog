<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>BTDig Results</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-slate-50 via-sky-50 to-indigo-50 min-h-screen text-slate-900">

<div class="container mx-auto px-6 py-10">

    <h1 class="text-4xl font-bold mb-8 text-center tracking-widest text-sky-700 drop-shadow-sm">
        🎬 Tokyo Hot Magnet List
    </h1>

    <div class="flex justify-between items-center mb-8">
        <div class="text-lg text-slate-700">
            已選擇：
            <span id="selectedCount" class="font-bold text-emerald-600">0</span>
            筆
        </div>

        <button onclick="copySelected()"
                class="bg-emerald-500 hover:bg-emerald-400 text-white px-6 py-2 rounded-lg shadow-lg transform hover:scale-105 transition duration-300">
            📋 複製 Magnet
        </button>
    </div>

    @php
        $grouped = collect($results)->groupBy('search_keyword');
    @endphp

    @foreach($grouped as $keyword => $items)

        <div class="mb-12">

            <h2 class="text-2xl font-bold mb-6 text-sky-700 border-l-4 border-sky-500 pl-4">
                🔎 關鍵字：{{ $keyword }}
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-5 gap-6">

                @foreach($items as $row)

                    <div class="card cursor-pointer bg-white/80 backdrop-blur border border-slate-200 rounded-xl p-6 shadow-md
                                transition duration-300 hover:-translate-y-2 hover:shadow-sky-500/20 hover:shadow-2xl"
                         data-magnet="{{ $row->magnet }}">

                        <input type="checkbox" class="hidden magnetCheckbox" value="{{ $row->magnet }}">

                        <h3 class="text-lg font-semibold mb-3 text-emerald-700">
                            {{ $row->name }}
                        </h3>

                        <div class="text-sm space-y-2 text-slate-600">
                            <div>💾 容量：{{ $row->size }}</div>
                            <div>📂 檔案數：{{ $row->files }}</div>
                            <div>⏳ 年齡：{{ $row->age }}</div>
                        </div>

                        <div class="mt-4 text-xs text-slate-500 break-all">
                            {{ Str::limit($row->magnet, 70) }}
                        </div>

                    </div>

                @endforeach

            </div>

        </div>

    @endforeach

</div>

<script>
    const cards = document.querySelectorAll('.card');
    const selectedCount = document.getElementById('selectedCount');

    cards.forEach(card => {
        card.addEventListener('click', function() {

            const checkbox = this.querySelector('.magnetCheckbox');
            checkbox.checked = !checkbox.checked;

            if (checkbox.checked) {
                this.classList.add(
                    'ring-4',
                    'ring-emerald-400',
                    'bg-emerald-50',
                    'shadow-emerald-400/30'
                );
                this.classList.remove('bg-white/80');
            } else {
                this.classList.remove(
                    'ring-4',
                    'ring-emerald-400',
                    'bg-emerald-50',
                    'shadow-emerald-400/30'
                );
                this.classList.add('bg-white/80');
            }

            updateCount();
        });
    });

    function updateCount() {
        const checked = document.querySelectorAll('.magnetCheckbox:checked');
        selectedCount.innerText = checked.length;
    }

    function copySelected() {
        const checked = document.querySelectorAll('.magnetCheckbox:checked');

        if (checked.length === 0) {
            alert("請先選擇項目");
            return;
        }

        let magnets = [];
        checked.forEach(cb => {
            magnets.push(cb.value);
        });

        navigator.clipboard.writeText(magnets.join("\n")).then(() => {
            alert("已複製 " + magnets.length + " 筆 Magnet");
        });
    }
</script>

</body>
</html>
