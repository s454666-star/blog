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
        @foreach($imagePaths as $imagePath)
            <img src="{{ $imagePath }}" class="gallery-image">
        @endforeach
    </div>
    <button id="load-more">Load More</button>
@endsection

@section('scripts')
    <script>
        let offset = 50;  // Start from the next batch after initial load
        const limit = 50;

        document.getElementById('load-more').addEventListener('click', function() {
            loadImages();
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
                        document.getElementById('load-more').remove();  // Remove button if no more images
                    }
                }).catch(error => console.error('Error loading images:', error));
        }
    </script>
@endsection
