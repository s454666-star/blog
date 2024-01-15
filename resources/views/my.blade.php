<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>吳偉誠的履歷</title>
    <style>
        body {
            font-family: 'Arial', sans-serif;
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            background-color: #e4f1fe; /* Light blue background */
        }
        .container {
            background-color: #fff;
            width: 80%;
            margin: 20px auto;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        .header {
            background-color: #34ace0; /* Blue header */
            color: #fff;
            text-align: center;
            padding: 20px 0;
        }
        .header img {
            border-radius: 50%;
            width: 120px;
            height: 120px;
            border: 4px solid #fff; /* White border for image */
        }
        .section {
            padding: 20px;
        }
        .section:nth-child(even) {
            background-color: #f7f1e3; /* Light yellow for alternate sections */
        }
        .section-title {
            color: #706fd3; /* Purple titles */
            margin-bottom: 15px;
            border-bottom: 2px solid #ff793f; /* Orange underline for titles */
            display: inline-block;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <img src="{{ asset('storage/img/my.jpg') }}" alt="Profile Picture">
        <h1>吳偉誠</h1>
        <p>後端工程師</p>
    </div>

    <div class="section">
        <h2 class="section-title">學歷</h2>
        <p>輔仁大學 資訊工程學系</p>
    </div>

    <div class="section">
        <h2 class="section-title">工作經歷</h2>
        <h3>工作一</h3>
        <p>使用技術: [技術詳細]</p>

        <h3>工作二</h3>
        <p>使用技術: [技術詳細]</p>

        <h3>工作三</h3>
        <p>使用技術: [技術詳細]</p>
    </div>
</div>
</body>
</html>
