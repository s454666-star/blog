<!-- resources/views/search.blade.php -->

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>影片搜尋</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/css/bootstrap.min.css">
    <!-- 引入 Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" crossorigin="anonymous" />
    <style>
        body {
            background: linear-gradient(135deg, #f0f4f8, #d9e2ec);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #2d3748;
            overflow-x: hidden;
        }
        .search-container {
            margin-top: 60px;
            margin-bottom: 40px;
            position: relative;
            z-index: 1;
        }
        .search-box {
            width: 100%;
            max-width: 700px;
            margin: auto;
            position: relative;
            display: flex;
            align-items: center;
        }
        .search-box input {
            height: 70px;
            font-size: 1.5rem;
            padding: 25px 60px 25px 30px;
            border-radius: 35px;
            border: none;
            box-shadow: 0 8px 16px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            width: 100%;
        }
        .search-box input:focus {
            outline: none;
            box-shadow: 0 12px 24px rgba(0,0,0,0.15);
        }
        .clear-button {
            position: absolute;
            right: 15px;
            background: rgba(255, 255, 255, 0.8);
            border: none;
            color: #a0aec0;
            font-size: 1.5rem;
            cursor: pointer;
            border-radius: 50%;
            padding: 5px;
            transition: background 0.3s ease, color 0.3s ease, transform 0.3s ease;
            display: none;
            z-index: 2;
        }
        .clear-button.show {
            display: block;
        }
        .clear-button:hover {
            background: #e2e8f0;
            color: #2d3748;
            transform: scale(1.1);
        }
        .video-card {
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 6px 12px rgba(0,0,0,0.1);
            transition: transform 0.3s, box-shadow 0.3s;
            overflow: hidden;
            margin-bottom: 40px;
            position: relative;
        }
        .video-card:hover {
            transform: translateY(-15px);
            box-shadow: 0 12px 24px rgba(0,0,0,0.2);
        }
        .video-thumbnail img, .video-thumbnail video {
            width: 100%;
            height: 200px;
            object-fit: cover;
        }
        .video-details {
            padding: 25px;
        }
        .video-title {
            font-size: 1.8rem;
            font-weight: bold;
            margin-bottom: 15px;
            color: #2b6cb0;
            transition: color 0.3s;
        }
        .video-title:hover {
            color: #2c5282;
        }
        .video-description {
            font-size: 1.1rem;
            color: #4a5568;
        }
        .pagination {
            justify-content: center;
            margin-top: 30px;
        }
        /* 背景動畫 */
        .bg-animation {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            overflow: hidden;
        }
        .bg-animation div {
            position: absolute;
            width: 250px;
            height: 250px;
            background: rgba(49, 130, 206, 0.1);
            border-radius: 50%;
            animation: float 20s infinite;
        }
        @keyframes float {
            0% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-150px) rotate(180deg); }
            100% { transform: translateY(0) rotate(360deg); }
        }
        /* 小圓點特效 */
        .bg-animation div:nth-child(1) {
            top: 15%;
            left: 10%;
            animation-delay: 0s;
        }
        .bg-animation div:nth-child(2) {
            top: 40%;
            left: 80%;
            animation-delay: 5s;
        }
        .bg-animation div:nth-child(3) {
            top: 70%;
            left: 30%;
            animation-delay: 10s;
        }
        .bg-animation div:nth-child(4) {
            top: 85%;
            left: 60%;
            animation-delay: 15s;
        }
        .bg-animation div:nth-child(5) {
            top: 50%;
            left: 5%;
            animation-delay: 20s;
        }
        /* 刪除按鈕樣式 */
        .delete-button {
            position: absolute;
            top: 15px;
            right: 15px;
            background-color: #e53e3e;
            border: none;
            color: #fff;
            padding: 8px 12px;
            border-radius: 50%;
            cursor: pointer;
            transition: background-color 0.3s ease, transform 0.3s ease;
            opacity: 0.8;
            z-index: 3; /* 確保刪除按鈕在最上層 */
        }
        .delete-button:hover {
            background-color: #c53030;
            transform: scale(1.1);
            opacity: 1;
        }
        /* 成功訊息樣式 */
        .alert-message {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1050;
            min-width: 250px;
        }
    </style>
</head>
<body>

<!-- 背景動畫 -->
<div class="bg-animation">
    <div></div>
    <div></div>
    <div></div>
    <div></div>
    <div></div>
</div>

<div class="container">
    <!-- 成功訊息 -->
    <div id="alert-container" class="alert-message"></div>

    <!-- 搜尋區塊 -->
    <div class="search-container">
        <div class="search-box position-relative">
            <form action="{{ route('videos.search') }}" method="GET" id="search-form" class="w-100">
                @csrf
                <input type="text" name="keyword" id="keyword-input" placeholder="搜尋相關影片..." value="{{ isset($keyword) ? $keyword : '' }}" oninput="toggleClearButton()">
                <button type="button" class="clear-button" id="clear-button" title="清除搜尋" onclick="clearSearch()">
                    <i class="fas fa-times-circle"></i>
                </button>
            </form>
        </div>
    </div>

    <!-- 影片列表 -->
    <div id="video-list">
        @if($keyword)
            @include('partials.video_list', ['videos' => $videos])
        @endif
    </div>

    @if($keyword)
        <!-- 分頁 -->
        <div class="row">
            <div class="col-12">
                {{ $videos->appends(['keyword' => $keyword])->links() }}
            </div>
        </div>
    @endif
</div>

<!-- 引入 jQuery 和 Bootstrap JS -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/js/bootstrap.min.js"></script>
<script>
    $(document).ready(function(){
        if('{{ $keyword }}') {
            $('.video-card').hide().fadeIn(1000);
            $('#clear-button').addClass('show');
        }

        // 監聽輸入框失去焦點事件
        $('#keyword-input').on('blur', function() {
            const keyword = $(this).val().trim();
            if (keyword !== '') {
                // 使用 AJAX 提交搜尋
                $.ajax({
                    url: "{{ route('videos.search') }}",
                    method: 'GET',
                    data: { keyword: keyword },
                    success: function(response) {
                        // 更新影片列表
                        $('#video-list').html(response);

                        // 添加淡入效果
                        $('.video-card').hide().fadeIn(1000);

                        // 顯示清除按鈕
                        $('#clear-button').addClass('show');
                    },
                    error: function() {
                        alert('搜尋失敗，請稍後再試。');
                    }
                });
            } else {
                // 如果關鍵字為空，清空影片列表及分頁
                $('#video-list').html('');
                // 隱藏清除按鈕
                $('#clear-button').removeClass('show');
            }
        });

        // 監聽刪除按鈕點擊事件
        $(document).on('click', '.delete-button', function(e){
            e.stopPropagation(); // 防止事件冒泡到父元素
            const videoId = $(this).data('id');
            const csrfToken = '{{ csrf_token() }}';

            $.ajax({
                url: `/videos/${videoId}`,
                method: 'DELETE',
                data: {
                    _token: csrfToken
                },
                success: function(response) {
                    if(response.success){
                        // 移除影片卡片
                        $(`#video-card-${videoId}`).fadeOut(500, function(){
                            $(this).remove();
                        });
                        // 顯示成功訊息
                        showAlert(response.message, 'success');
                    } else {
                        // 顯示失敗訊息
                        showAlert(response.message, 'danger');
                    }
                },
                error: function(xhr){
                    let message = '刪除失敗，請稍後再試。';
                    if(xhr.responseJSON && xhr.responseJSON.message){
                        message = xhr.responseJSON.message;
                    }
                    showAlert(message, 'danger');
                }
            });
        });
    });

    // 切換清除按鈕顯示
    function toggleClearButton() {
        const keyword = $('#keyword-input').val().trim();
        if(keyword !== '') {
            $('#clear-button').addClass('show');
        } else {
            $('#clear-button').removeClass('show');
        }
    }

    // 清除搜尋
    function clearSearch() {
        $('#keyword-input').val('');
        $('#video-list').html('');
        $('#clear-button').removeClass('show');
        $('#keyword-input').blur();
    }

    // 顯示訊息
    function showAlert(message, type){
        const alertHtml = `
            <div class="alert alert-${type} alert-dismissible fade show" role="alert">
                ${message}
            </div>
        `;
        $('#alert-container').html(alertHtml);

        // 自動消失
        setTimeout(function(){
            $('.alert').alert('close');
        }, 3000);
    }
</script>
</body>
</html>
