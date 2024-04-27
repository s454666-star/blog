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
        {{-- Initial images loaded via controller --}}
    </div>
    <button id="load-more">Load More</button>
@endsection

@section('scripts')
    <script>
        let offset = 0;
        const limit = 50; // Number of images to load per request

        document.addEventListener("DOMContentLoaded", function() {
            loadImages(); // Load initial set of images

            // Add event listeners for image hover effect
            document.getElementById('gallery-container').addEventListener('mouseover', function(event) {
                if (event.target.className === 'gallery-image') {
                    event.target.style.opacity = 0.7;
                }
            });
            document.getElementById('gallery-container').addEventListener('mouseout', function(event) {
                if (event.target.className === 'gallery-image') {
                    event.target.style.opacity = 1.0;
                }
            });

            // Infinite scroll or button click to load more images
            document.getElementById('load-more').addEventListener('click', function() {
                loadImages();
            });
        });

        function loadImages() {
            fetch(`/gallery/load-images?offset=${offset}`)
                .then(response => response.json())
                .then(data => {
                    if (data.length > 0) {
                        data.forEach(imagePath => {
                            const img = document.createElement('img');
                            img.src = imagePath;
                            img.className = 'gallery-image';
                            document.getElementById('gallery-container').appendChild(img);
                        });
                        offset += limit;
                    } else {
                        document.getElementById('load-more').remove(); // Remove button if no more images
                    }
                }).catch(error => console.error('Error loading images:', error));
        }
    </script>
@endsection
