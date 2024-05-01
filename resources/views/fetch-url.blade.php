<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>商品上架程式</title>
    <style>
        body, html {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            height: 100vh;
            width: 100vw;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(120deg, #a1c4fd, #c2e9fb);
        }

        .container {
            width: 70%;
            height: 70%;
            background-color: rgba(255, 255, 255, 0.9);
            padding: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            overflow: auto;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .title {
            font-size: 24px;
            color: #007BFF;
            margin-bottom: 20px;
            font-weight: bold;
            text-shadow: 1px 1px 2px #aaaaaa;
        }

        input[type="text"], textarea {
            width: 70%;
            padding: 10px;
            margin: 10px 0;
            border: 1px solid #007BFF;
            border-radius: 5px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        input[type="text"]:focus, textarea:focus {
            border-color: #0056b3;
            box-shadow: 0 0 8px #add8e6;
        }

        button {
            padding: 10px 20px;
            color: white;
            background-color: #007BFF;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        button:hover {
            background-color: #0056b3;
        }

        button.disabled {
            background-color: #ff4500; /* 橙紅色 */
            animation: blinker 1s linear infinite;
            cursor: not-allowed;
            box-shadow: 0 0 12px #ff4500;
        }

        @keyframes blinker {
            50% {
                opacity: 0.5;
            }
        }

        textarea {
            height: calc(20em + 3000px); /* 將原始高度增加 60px 以容納更多行 */
        }
    </style>
</head>
<body>
<div class="container">
    <div class="title">商品上架程式</div>
    <input type="text" id="url" placeholder="請輸入URL">
    <button onclick="fetchData()">抓取資料</button>
    <textarea id="data" placeholder="顯示資料..."></textarea>
</div>

<script>
    function fetchData() {
        const url = document.getElementById('url').value;
        const button = document.querySelector('button');
        const dataTextarea = document.getElementById('data');

        button.textContent = '抓取中...';
        button.classList.add('disabled');
        button.disabled = true;

        fetch('/fetch', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify({url: url})
        })
            .then(response => response.text())
            .then(html => {
                dataTextarea.value = html;
                button.textContent = '抓取資料';
                button.classList.remove('disabled');
                button.disabled = false;
            })
            .catch(error => {
                console.error('Error:', error);
                button.textContent = '抓取資料';
                button.classList.remove('disabled');
                button.disabled = false;
            });
    }
</script>
</body>
</html>
