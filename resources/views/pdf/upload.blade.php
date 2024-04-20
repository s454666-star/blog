<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>上傳 PDF</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-5">
    <h1 class="mb-4">上傳 PDF 文件</h1>
    <form action="https://s2.starweb.life/upload-pdf" method="POST" enctype="multipart/form-data">
        @csrf
        <div class="mb-3">
            <label for="pdf" class="form-label">PDF 文件</label>
            <input type="file" class="form-control" id="pdf" name="pdf" required>
        </div>
        <button type="submit" class="btn btn-primary">上傳並提取文字</button>
    </form>
</div>
</body>
</html>
