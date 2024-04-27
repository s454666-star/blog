@extends('layouts.app')

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
            padding: 30px;
            background: #FFF;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.5); /* Shadow for depth */
            overflow: visible;
        }

        .gallery {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(100px, 1fr)); /* Responsive grid layout */
            gap: 5px;
            justify-items: center;
            align-items: center;
            overflow: visible;
        }

        .gallery-item {
            transition: transform 0.6s ease; /* Smooth transition for transform */
            cursor: pointer; /* Indicates that the item can be interacted with */
            position: relative; /* Ensures proper stacking and positioning */
            overflow: hidden; /* Keeps the overflowed part of the scaled image hidden */
        }

        .gallery-item:hover {
            z-index: 10; /* Ensures the item is displayed above others */
            overflow: visible; /* Shows the overflowed part of the image */
        }

        .gallery-item:hover .gallery-image {
            transform: scale(1); /* Resets any scaling */
            position: absolute;
            left: 50%;
            top: 50%;
            transform: translate(-50%, -50%); /* Center the image */
            min-width: 100%; /* Ensures image takes full width of the container */
            min-height: 100%; /* Ensures image takes full height of the container */
            width: auto; /* Maintains aspect ratio */
            height: auto; /* Maintains aspect ratio */
            max-width: none; /* Removes max-width restriction */
        }

        .gallery-image {
            width: 100%; /* Adjusts the width of the image to fit the container */
            height: auto;
            display: block;
            transition: transform 0.3s ease; /* Smooth transition for scaling */
        }
    </style>

@endsection

@section('content')
    <div class="frame">
        <div class="gallery" id="gallery-container">
            @foreach ($imagePaths as $imagePath)
                <div class="gallery-item" data-original="{{ $imagePath }}">
                    <img src="{{ $imagePath }}" class="gallery-image">
                </div>
            @endforeach
        </div>
    </div>
    @if (count($imagePaths) >= 50)
        <button id="load-more">Load More</button>
    @endif
@endsection



@section('scripts')
    <script>
        let offset = {{ count($imagePaths) }};
        const limit = 50;

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


        document.querySelectorAll('.gallery-item').forEach(item => {
            item.addEventListener('mouseover', function () {
                // Assuming you have a modal or a tooltip element to update
                document.getElementById('image-preview').src = this.getAttribute('data-original');
                document.getElementById('image-preview').style.display = 'block';
            });

            item.addEventListener('mouseout', function () {
                document.getElementById('image-preview').style.display = 'none';
            });
        });
    </script>
@endsection