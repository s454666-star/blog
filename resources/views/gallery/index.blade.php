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
        let offset = 0;
        const limit = 10;  // Update as necessary for your configuration

        function loadImages() {
            fetch(`/gallery?offset=${offset}`)
                .then(response => response.json())
                .then(images => {
                    const container = document.getElementById('gallery-container');
                    images.forEach(image => {
                        const img = document.createElement('img');
                        img.src = image;  // Make sure `image` is the correct path
                        img.className = 'gallery-image';
                        container.appendChild(img);
                    });
                    offset += images.length;
                    if (images.length === 0 || images.length < limit) {
                        document.getElementById('load-more').style.display = 'none';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                });
        }

        document.addEventListener('DOMContentLoaded', function() {
            loadImages();  // Load initial set of images
        });

        document.getElementById('load-more').addEventListener('click', loadImages);
    </script>
@endsection
