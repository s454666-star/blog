<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload PDF</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-5">
    <h1 class="mb-4">Upload a PDF file</h1>
    <form action="{{ route('pdf.extract-text') }}" method="POST" enctype="multipart/form-data">
        @csrf
        <div class="mb-3">
            <label for="pdf" class="form-label">PDF File</label>
            <input type="file" class="form-control" id="pdf" name="pdf" required>
        </div>
        <button type="submit" class="btn btn-primary">Upload and Extract Text</button>
    </form>
</div>
</body>
</html>
