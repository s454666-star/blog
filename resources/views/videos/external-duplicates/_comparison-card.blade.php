@php
    $showCheckbox = (bool) ($showCheckbox ?? false);
    $similarityValue = isset($entry['similarity_percent']) ? (float) $entry['similarity_percent'] : null;
    $toneClass = 'soft';

    if ($similarityValue !== null) {
        if ($similarityValue >= 95) {
            $toneClass = 'excellent';
        } elseif ($similarityValue >= 90) {
            $toneClass = 'good';
        } elseif ($similarityValue >= 80) {
            $toneClass = 'warn';
        } elseif ($similarityValue <= 0) {
            $toneClass = 'bad';
        }
    } elseif (($entry['status_tone'] ?? '') === 'bad') {
        $toneClass = 'bad';
    }
@endphp

<article class="match-card" @if($showCheckbox) data-match-id="{{ $entry['id'] }}" @endif>
    <div class="match-head">
        <div class="match-title">
            @if ($showCheckbox)
                <input class="match-checkbox" data-match-checkbox type="checkbox" value="{{ $entry['id'] }}">
            @endif
            <div>
                <h2 class="match-name">{{ $entry['file_name'] }}</h2>
                <div class="match-subtitle">{{ $entry['source_file_path'] }}</div>
            </div>
        </div>

        <div class="match-head-right">
            <span class="tone-chip {{ $toneClass }}">
                <strong>{{ $similarityValue !== null ? number_format($similarityValue, 2) . '%' : '--' }}</strong> 總相似度
            </span>
            <span class="tone-chip"><strong>{{ (int) ($entry['matched_frames'] ?? 0) }}/{{ (int) ($entry['compared_frames'] ?? 0) }}</strong> 命中張數</span>
            @if (!empty($entry['required_matches']))
                <span class="tone-chip"><strong>{{ (int) $entry['required_matches'] }}</strong> 需要達標</span>
            @endif
            <span class="tone-chip {{ $entry['status_tone'] ?? 'soft' }}"><strong>{{ $entry['status_label'] ?? '-' }}</strong></span>
            <span class="tone-chip {{ $entry['operation_tone'] ?? 'soft' }}"><strong>{{ $entry['operation_label'] ?? '-' }}</strong></span>
        </div>
    </div>

    <div class="compare-panels">
        <section class="panel">
            <div class="panel-head">
                <div class="panel-title">
                    <strong>{{ ($entry['type'] ?? '') === 'duplicate' ? '外部疑似重複檔' : '本次比對來源' }}</strong>
                    <span>
                        @if (!empty($entry['duplicate_directory_path']) && $entry['duplicate_directory_path'] !== '-')
                            目前位於：{{ $entry['duplicate_directory_path'] }}
                        @else
                            來源路徑：{{ $entry['source_file_path'] }}
                        @endif
                    </span>
                </div>
            </div>

            <div class="video-box">
                @if (!empty($entry['external_stream_url']) && !empty($entry['duplicate_file_exists']))
                    <video controls preload="metadata" playsinline>
                        <source src="{{ $entry['external_stream_url'] }}">
                    </video>
                @else
                    <div class="video-fallback">
                        這筆資料以比對截圖為主。<br>
                        @if (($entry['type'] ?? '') === 'duplicate')
                            @if (!empty($entry['duplicate_file_exists']))
                                可直接用下方截圖與 DB 影片確認。
                            @else
                                找不到外部影片檔案，請用下方截圖確認。
                            @endif
                        @else
                            log 僅保留比對截圖與特徵，不額外串流原始檔案。
                        @endif
                    </div>
                @endif
            </div>

            <div class="meta-grid">
                <div class="meta">
                    <div class="meta-label">外部檔名</div>
                    <div class="meta-value">{{ $entry['file_name'] }}</div>
                </div>
                <div class="meta">
                    <div class="meta-label">時長 / 大小</div>
                    <div class="meta-value">{{ $entry['duration_hms'] }} / {{ $entry['file_size_human'] }}</div>
                </div>
                <div class="meta">
                    <div class="meta-label">來源建立時間</div>
                    <div class="meta-value">{{ $entry['file_created_at_human'] }}</div>
                </div>
                <div class="meta">
                    <div class="meta-label">來源修改時間</div>
                    <div class="meta-value">{{ $entry['file_modified_at_human'] }}</div>
                </div>
                <div class="meta">
                    <div class="meta-label">比對時間</div>
                    <div class="meta-value">{{ $entry['created_at_human'] ?? '-' }}</div>
                </div>
                <div class="meta">
                    <div class="meta-label">候選數 / 門檻</div>
                    <div class="meta-value">
                        {{ isset($entry['candidate_count']) ? (int) $entry['candidate_count'] : 0 }} 候選 /
                        {{ (int) ($entry['threshold_percent'] ?? 0) }}%
                    </div>
                </div>
                <div class="meta" style="grid-column:1 / -1;">
                    <div class="meta-label">來源路徑</div>
                    <div class="meta-value">{{ $entry['source_file_path'] }}</div>
                </div>
                @if (!empty($entry['duplicate_file_path']))
                    <div class="meta" style="grid-column:1 / -1;">
                        <div class="meta-label">疑似重複路徑</div>
                        <div class="meta-value">{{ $entry['duplicate_file_path'] }}</div>
                    </div>
                @endif
                @if (!empty($entry['operation_message']))
                    <div class="meta" style="grid-column:1 / -1;">
                        <div class="meta-label">執行訊息</div>
                        <div class="meta-value">{{ $entry['operation_message'] }}</div>
                    </div>
                @endif
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <div class="panel-title">
                    <strong>
                        DB 影片
                        @if (!empty($entry['db_video_id']))
                            #{{ $entry['db_video_id'] }} {{ $entry['db_video_name'] }}
                        @else
                            未命中或已不存在
                        @endif
                    </strong>
                    <span>
                        @if (!empty($entry['db_video_id']))
                            比對候選 / 已命中 DB 特徵
                        @else
                            這次比對沒有可用的 DB 候選詳細資料
                        @endif
                    </span>
                </div>

                @if (!empty($entry['db_video_page_url']))
                    <a class="btn btn-soft" href="{{ $entry['db_video_page_url'] }}" target="_blank" rel="noreferrer">打開 DB 影片頁</a>
                @endif
            </div>

            <div class="video-box">
                @if (!empty($entry['db_video_url']))
                    <video controls preload="metadata" playsinline>
                        <source src="{{ $entry['db_video_url'] }}">
                    </video>
                @else
                    <div class="video-fallback">
                        找不到 DB 影片或播放路徑。<br>
                        仍可用下方截圖與 feature 資料人工確認。
                    </div>
                @endif
            </div>

            <div class="meta-grid">
                <div class="meta">
                    <div class="meta-label">DB 影片名稱</div>
                    <div class="meta-value">{{ $entry['db_video_name'] ?? '-' }}</div>
                </div>
                <div class="meta">
                    <div class="meta-label">DB 時長 / 大小</div>
                    <div class="meta-value">{{ $entry['db_duration_hms'] ?? '-' }} / {{ $entry['db_file_size_human'] ?? '-' }}</div>
                </div>
                <div class="meta">
                    <div class="meta-label">相差秒數</div>
                    <div class="meta-value">
                        {{ isset($entry['duration_delta_seconds']) && $entry['duration_delta_seconds'] !== null ? number_format((float) $entry['duration_delta_seconds'], 3) . ' 秒' : '-' }}
                    </div>
                </div>
                <div class="meta">
                    <div class="meta-label">相差大小</div>
                    <div class="meta-value">
                        {{ isset($entry['file_size_delta_bytes']) && $entry['file_size_delta_bytes'] !== null ? number_format(abs((int) $entry['file_size_delta_bytes'])) . ' bytes' : '-' }}
                    </div>
                </div>
                <div class="meta">
                    <div class="meta-label">要求命中張數</div>
                    <div class="meta-value">
                        {{ isset($entry['requested_min_match']) ? (int) $entry['requested_min_match'] : '-' }}
                        @if (!empty($entry['required_matches']))
                            / 實際 {{ (int) $entry['required_matches'] }}
                        @endif
                    </div>
                </div>
                <div class="meta">
                    <div class="meta-label">狀態</div>
                    <div class="meta-value">{{ $entry['operation_label'] ?? '-' }}</div>
                </div>
                <div class="meta" style="grid-column:1 / -1;">
                    <div class="meta-label">DB 影片路徑</div>
                    <div class="meta-value">{{ $entry['db_video_path'] ?? '-' }}</div>
                </div>
            </div>
        </section>
    </div>

    <section class="compare-strip">
        <div class="strip-head">
            <div>
                <h2>逐張截圖對照</h2>
                <p>左邊是來源截圖，右邊是 DB 候選截圖。新 log 直接讀資料庫內的 base64。</p>
            </div>
            <span class="selection-chip">門檻 <strong>{{ (int) ($entry['threshold_percent'] ?? 0) }}%</strong></span>
        </div>

        @if (empty($entry['frames']))
            <div class="missing-box">這筆資料沒有可顯示的截圖比較。</div>
        @else
            @foreach ($entry['frames'] as $frame)
                <div class="frame-row">
                    <figure class="frame-figure">
                        @if (!empty($frame['source_image_src']))
                            <img src="{{ $frame['source_image_src'] }}" alt="來源截圖 {{ $frame['capture_order'] }}">
                        @else
                            <div class="missing-box">找不到來源截圖</div>
                        @endif
                        <figcaption class="frame-caption">
                            <span>來源截圖 #{{ $frame['capture_order'] }}</span>
                            <span>{{ isset($frame['source_capture_second']) && $frame['source_capture_second'] !== null ? number_format((float) $frame['source_capture_second'], 3) . ' 秒' : '-' }}</span>
                        </figcaption>
                    </figure>

                    <div class="frame-center">
                        <div class="score-ring">
                            {{ isset($frame['similarity_percent']) && $frame['similarity_percent'] !== null ? (int) round((float) $frame['similarity_percent']) . '%' : '--' }}
                        </div>
                        <span class="tone-chip {{ $frame['tone'] ?? 'soft' }}">
                            <strong>{{ !empty($frame['is_threshold_match']) ? '達門檻' : '未達門檻' }}</strong>
                        </span>
                        <div class="score-label">
                            逐張 dHash 相似度
                            {{ isset($frame['similarity_percent']) && $frame['similarity_percent'] !== null ? (int) round((float) $frame['similarity_percent']) . '%' : '--' }}
                        </div>
                    </div>

                    <figure class="frame-figure">
                        @if (!empty($frame['db_image_src']))
                            <img src="{{ $frame['db_image_src'] }}" alt="DB 截圖 {{ $frame['capture_order'] }}">
                        @else
                            <div class="missing-box">找不到 DB 截圖</div>
                        @endif
                        <figcaption class="frame-caption">
                            <span>DB 截圖 #{{ $frame['capture_order'] }}</span>
                            <span>{{ isset($frame['db_capture_second']) && $frame['db_capture_second'] !== null ? number_format((float) $frame['db_capture_second'], 3) . ' 秒' : '-' }}</span>
                        </figcaption>
                    </figure>
                </div>
            @endforeach
        @endif
    </section>
</article>
