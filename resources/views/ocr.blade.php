<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OCR Image Upload</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            background-color: #f4f4f4;
        }
        .container {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }
        input[type="file"] {
            margin-top: 10px;
        }
        button {
            margin-top: 10px;
            padding: 10px 20px;
            font-size: 16px;
            background-color: #007bff;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        #result {
            margin-top: 20px;
            padding: 10px;
            background-color: #ddd;
            border-radius: 5px;
        }
    </style>
</head>
<body>
<div class="container">
    <h1>Upload an Image for OCR</h1>
    <form action="/ocr" method="post" enctype="multipart/form-data">
        @csrf
        <input type="file" name="image" required>
        <button type="submit">Upload and Recognize Text</button>
    </form>
    @if(session('text'))
        <div id="result">
            <h3>Recognized Text:</h3>
            <p>{{ session('text') }}</p>
        </div>
    @endif
</div>
</body>
</html>
