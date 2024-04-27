@extends('layouts.app')  {{-- Assuming you have a main layout file --}}

@section('head')
    <style>
        body {
            background-color: #f4f4f4;
            color: #333;
            font-family: 'Arial', sans-serif;
        }

        .frame {
            max-width: 90vw; /* Limit the frame width to 90% of the viewport width */
            margin: 20px auto; /* Center the frame horizontally */
            border: 10px solid #8B4513; /* Thick solid border to mimic a wooden frame */
            padding: 10px;
            background: #FFF;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.5); /* Shadow for depth */
            overflow: hidden; /* Ensures no overflow of content outside the frame */
        }

        .gallery {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(100px, 1fr)); /* Responsive grid layout */
            gap: 5px;
            justify-items: center;
            align-items: center;
        }

        .gallery-item {
            transition: transform 0.2s ease-in-out;
            cursor: pointer; /* Indicates the item can be interacted with */
        }

        .gallery-item:hover {
            transform: scale(1.1); /* Slightly enlarges the photo on hover */
        }

        .gallery-image {
            width: 100%; /* Adjusts the width of the image to fit the container */
            height: auto;
            display: block;
        }
    </style>

@endsection

@section('content')
    <div class="frame">
        <div class="gallery" id="gallery-container">
            @foreach ($imagePaths as $imagePath)
                <div class="gallery-item">
                    <img src="{{ $imagePath }}" class="gallery-image">
                </div>
            @endforeach
        </div>
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
                        const div = document.createElement('div');
                        div.className = 'gallery-item';
                        div.appendChild(img);
                        container.appendChild(div);
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