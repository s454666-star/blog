<!DOCTYPE html>
<html>
<head>
    <title>PDF Text</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-5">
    <h1>Extracted Text</h1>
    <div class="alert alert-info" style="white-space: pre-wrap;">{{ $text }}</div>
</div>
<div class="container mt-5">
    <h1>Extracted Text</h1>
    <div class="alert alert-info" style="white-space: pre-wrap;">{{ $text }}</div>
    <a href="{{ route('export_excel') }}" class="btn btn-success">匯出 Excel</a>
</div>
</body>
</html>
