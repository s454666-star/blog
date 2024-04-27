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

        .gallery-item:hover {
            transform: scale(3); /* Enlarge the photo 3 times on hover */
            z-index: 10; /* Ensure the enlarged image is above others */
            position: relative; /* Required for proper z-index handling */
        }

        .gallery-item:hover {
            transform: scale(1.1); /* Slightly enlarges the photo on hover */
        }

        .gallery-image {
            display: block;
            max-width: 100%; /* Ensures the image does not exceed the container's width */
            height: auto; /* Maintains aspect ratio */
            object-fit: cover; /* Ensures the image covers the set dimensions */
        }

        .gallery-item {
            width: auto;
            height: 500px; /* Default height to maintain unless the width needs to be >500 */
        }
    </style>
@endsection

@section('content')
    <div class="frame">
        <div class="gallery" id="gallery-container">
            @foreach ($imagePaths as $imagePath)
                <div id="image-preview"
                     style="display:none; position: fixed; z-index: 100; top: 50%; left: 50%; transform: translate(-50%, -50%);">
                    <img src="" alt="Image Preview" style="max-width: 90vw; max-height: 90vh;">
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

        document.addEventListener('DOMContentLoaded', function () {
            const loadMoreButton = document.getElementById('load-more');
            if (loadMoreButton) {
                loadMoreButton.addEventListener('click', function () {
                    loadImages();
                });
            }

            const galleryItems = document.querySelectorAll('.gallery-item');
            galleryItems.forEach(item => {
                item.addEventListener('mouseover', function () {
                    const imagePreview = document.getElementById('image-preview');
                    if (imagePreview) {
                        const img = imagePreview.querySelector('img');
                        img.src = this.getAttribute('data-original');
                        imagePreview.style.display = 'block';
                    }
                });

                item.addEventListener('mouseout', function () {
                    const imagePreview = document.getElementById('image-preview');
                    if (imagePreview) {
                        imagePreview.style.display = 'none';
                    }
                });
            });
        });

        function loadImages() {
            const offset = parseInt(document.getElementById('gallery-container').getAttribute('data-offset'), 10) || 0;
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
                    const newOffset = offset + images.length;
                    container.setAttribute('data-offset', newOffset);
                    if (images.length < 50) {
                        const loadMoreButton = document.getElementById('load-more');
                        if (loadMoreButton) {
                            loadMoreButton.style.display = 'none';
                        }
                    }
                })
                .catch(error => console.error('Error:', error));
        }
    </script>
@endsection