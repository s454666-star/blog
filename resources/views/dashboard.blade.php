<!-- resources/views/dashboard.blade.php -->
<!DOCTYPE html>
<html lang="zh-TW">
@if (session('message'))
    <div class="alert alert-success">
        {{ session('message') }}
    </div>
@endif
<head>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta charset="UTF-8">
    <title>登入成功</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        /* 在這裡添加自定義 CSS 樣式 */
    </style>
</head>
<body>
<div class="container mt-5">
    <h2 class="mb-4">登入成功！歡迎來到您的儀表板</h2>
    <button id="showChats" class="btn btn-primary">顯示聊天室列表</button>
    <div id="chatList" class="mt-3" style="display:none;">
        <!-- 聊天室列表將在這裡顯示 -->
    </div>
</div>

<script>
    // 在這裡添加 JavaScript 代碼來處理按鈕點擊事件和 AJAX 請求
</script>
</body>
</html>
<style>
    #chatList {
        max-height: 400px;
        overflow-y: auto;
        background-color: #f7f7f7;
        border: 1px solid #ddd;
        border-radius: 0.25rem;
        padding: 1rem;
    }

    .chat-item {
        padding: 0.5rem 1rem;
        margin-bottom: 0.5rem;
        background: white;
        border: 1px solid #eee;
        border-radius: 0.25rem;
    }
</style>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        document.getElementById('showChats').addEventListener('click', function() {
            var chatListDiv = document.getElementById('chatList');
            if (chatListDiv.style.display === 'none' || chatListDiv.style.display === '') {
                fetchChatList();
            } else {
                chatListDiv.style.display = 'none';
            }
        });

        function fetchChatList() {
            var chatListDiv = document.getElementById('chatList');
            fetch('/get-chat-list', {
                headers: {
                    "X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                }
            })
                .then(response => response.json())
                .then(data => {
                    chatListDiv.innerHTML = '';
                    data.forEach(chat => {
                        var chatItem = document.createElement('div');
                        chatItem.classList.add('chat-item');
                        chatItem.textContent = chat.name;
                        chatListDiv.appendChild(chatItem);
                    });
                    chatListDiv.style.display = 'block';
                })
                .catch(error => console.error('Error fetching chat list:', error));
        }
    });
</script>