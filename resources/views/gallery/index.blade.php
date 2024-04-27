@extends('layouts.app')  {{-- Assuming you have a main layout file --}}

@section('head')
    <style>
        body {
            background-color: #f4f4f4;
            color: #333;
            font-family: 'Arial', sans-serif;
        }
        .gallery {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-around;
            padding: 20px;
        }
        .gallery-item {
            margin: 10px;
            border: 3px solid #fff;
            box-shadow: 0 0 8px rgba(0,0,0,0.3);
            transition: transform 0.2s ease-in-out;
        }
        .gallery-item:hover {
            transform: scale(1.05);
        }
        .gallery-image {
            width: 100%;
            height: auto;
            display: block;
        }
    </style>
@endsection

@section('content')
    <div class="gallery" id="gallery-container">

    </div>
    <button id="load-more">Load More</button>
@endsection

@section('scripts')
    <script>
        let offset = 0;  // Start from the initial batch
        const limit = 10;  // Adjust based on your backend setup

        document.getElementById('load-more').addEventListener('click', function() {
            loadImages();
        });

        // Function to fetch and display images
        function loadImages() {
            fetch(`/gallery?offset=${offset}`)  // Adjust the API endpoint as needed
                .then(response => response.json())
                .then(data => {
                    if (data.length > 0) {
                        data.forEach(imagePath => {
                            const img = document.createElement('img');
                            img.src = imagePath;
                            img.className = 'gallery-image';
                            document.getElementById('gallery-container').appendChild(img);
                        });
                        offset += data.length;  // Update offset based on number of images loaded
                    } else {
                        document.getElementById('load-more').remove();  // Remove button if no more images
                    }
                })
                .catch(error => console.error('Error loading images:', error));
        }

        // Initial load
        loadImages();
    </script>
@endsection
