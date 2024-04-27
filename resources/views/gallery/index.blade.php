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
            box-shadow: 0 0 8px rgba(0, 0, 0, 0.3);
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
        @foreach ($imagePaths as $imagePath)
            <div class="gallery-item">
                <img src="{{ $imagePath }}" class="gallery-image">
            </div>
        @endforeach
    </div>
    @if (count($imagePaths) >= 10)
        <button id="load-more">Load More</button>
    @endif
@endsection


@section('scripts')
    <script>
        let offset = {{ count($imagePaths) }};
        const limit = 10;

        document.getElementById('load-more').addEventListener('click', function () {
            loadImages();
        });

        function loadImages() {
            fetch(`/gallery?offset=${offset}`)
                .then(response => response.json())
                .then(images => {
                    const container = document.getElementById('gallery-container');
                    images.forEach(image => {
                        const img = document.createElement('img');
                        img.src = image;
                        img.className = 'gallery-image';
                        container.appendChild(img);
                    });
                    offset += images.length;
                    if (images.length < limit) {
                        document.getElementById('load-more').style.display = 'none';
                    }
                })
                .catch(error => console.error('Error:', error));
        }
    </script>
@endsection