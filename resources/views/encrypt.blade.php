<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>碎形加密儲存系統</title>
    <style>
        /* 全域樣式 */
        body {
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #ff9a9e, #fad0c4);
            color: #333;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            min-height: 100vh;
        }

        h1 {
            margin-top: 40px;
            font-size: 3rem;
            color: #4a90e2;
            text-shadow: 2px 4px 6px rgba(0, 0, 0, 0.2);
        }

        h2 {
            color: #555;
            font-size: 1.5rem;
            margin-bottom: 15px;
        }

        .container {
            background: #ffffff;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
            border-radius: 12px;
            padding: 20px;
            width: 80%;
            max-width: 800px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 30px;
        }

        .block {
            width: 45%;
            text-align: center;
        }

        button {
            background: #4a90e2;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            font-size: 1rem;
            cursor: pointer;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            transition: transform 0.2s, background 0.3s;
        }

        button:hover {
            background: #357ab8;
            transform: translateY(-3px);
        }

        button:active {
            transform: translateY(0);
        }

        input[type="file"], input[type="text"] {
            width: 80%;
            padding: 10px;
            margin: 10px 0;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 1rem;
        }

        .file-list {
            margin-top: 20px;
            width: 80%;
        }

        .file-list h2 {
            font-size: 1.25rem;
            color: #4a90e2;
            text-align: center;
        }

        .file-list ul {
            list-style-type: none;
            padding: 0;
        }

        .file-list li {
            margin: 10px 0;
            padding: 10px;
            background: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 5px;
            position: relative;
            cursor: pointer;
        }

        .file-list li:hover {
            background: #f1f1f1;
        }

        .file-list li form {
            display: inline;
        }

        .file-list li button {
            padding: 5px 10px;
            font-size: 0.9rem;
            margin-left: 5px;
        }

        .details {
            display: none;
            margin-top: 10px;
            padding: 10px;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 5px;
        }

        .details ul {
            list-style-type: none;
            padding: 0;
        }

        .details li {
            margin: 5px 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .details li form {
            display: inline;
        }

        .details li button {
            padding: 5px 10px;
            font-size: 0.9rem;
            margin-left: 5px;
        }
    </style>
</head>
<body>
<h1>碎形加密儲存系統</h1>

<!-- 功能區 -->
<div class="container">
    <!-- 加密檔案 -->
    <div class="block">
        <h2>加密檔案</h2>
        <form action="{{ route('encrypt.file') }}" method="POST" enctype="multipart/form-data">
            @csrf
            <input type="file" name="file" required>
            <br>
            <button type="submit">加密並分割</button>
        </form>
    </div>

    <!-- 還原檔案 -->
    <div class="block">
        <h2>還原檔案</h2>
        <form action="{{ route('decrypt.folder') }}" method="POST">
            @csrf
            <input type="text" name="folder" placeholder="輸入資料夾名稱" required>
            <br>
            <button type="submit">還原檔案</button>
        </form>
    </div>
</div>

<!-- 檔案清單 -->
<div class="file-list">
    <h2>加密後的資料夾清單</h2>
    <ul>
        @foreach ($encryptedFolders as $folder => $details)
            <li onclick="toggleDetails('{{ $folder }}')">
                {{ $folder }}
                <form action="{{ route('download.folder', ['folder' => $folder]) }}" method="POST" style="display: inline;">
                    @csrf
                    <button type="submit">下載整批</button>
                </form>
                <form action="{{ route('delete.folder', ['folder' => $folder]) }}" method="POST" style="display: inline;">
                    @csrf
                    @method('DELETE')
                    <button type="submit" style="background-color: red; color: white;">刪除整批</button>
                </form>
                <div class="details" id="details-{{ $folder }}">
                    <ul>
                        @foreach ($details as $file)
                            <li>
                                {{ $file }}
                                <form action="{{ route('download.chunk', ['folder' => $folder, 'file' => $file]) }}" method="POST">
                                    @csrf
                                    <button type="submit">下載</button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </li>
        @endforeach
    </ul>

    <h2>還原後的檔案清單</h2>
    <ul>
        @foreach ($restoredFiles as $file)
            <li>
                {{ $file }}
                <form action="{{ route('download.file', ['file' => $file]) }}" method="POST">
                    @csrf
                    <button type="submit">下載檢查</button>
                </form>
                <form action="{{ route('delete.file', ['file' => $file]) }}" method="POST">
                    @csrf
                    @method('DELETE')
                    <button type="submit" style="background-color: red; color: white;">刪除檔案</button>
                </form>
            </li>
        @endforeach
    </ul>
</div>

<script>
    function toggleDetails(folderId) {
        const details = document.getElementById('details-' + folderId);
        if (details.style.display === 'none' || details.style.display === '') {
            details.style.display = 'block';
        } else {
            details.style.display = 'none';
        }
    }
</script>
</body>
</html>
