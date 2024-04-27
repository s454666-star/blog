@extends('layouts.app')

@section('head')
    <style>
        body {
            background-color: #f4f4f4;
            color: #333;
            font-family: 'Arial', sans-serif;
        }

        .frame {
            max-width: 100vw;
            width: auto;
            margin: 20px auto;
            border: 10px solid #8B4513;
            padding: 30px;
            background: #FFF;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.5);
            overflow: visible; /* Allow the frame to show everything */
            position: relative;
        }

        .gallery {
            display: flex; /* Changed to flex to better manage wrapping */
            flex-wrap: wrap; /* Allow items to wrap to the next line */
            justify-content: center; /* Center items horizontally */
            align-items: flex-start; /* Align items to the top */
            gap: 5px; /* Maintain spacing between items */
        }


        .gallery-item {
            flex: 1 0 100%; /* Each item can grow to fill the space but starts out taking full width */
            position: relative;
            cursor: pointer;
            overflow: visible; /* Make overflow visible to allow the image to spill out */
            transition: transform 0.3s ease;
            width: auto; /* Width auto to allow natural size */
            min-width: 100px; /* Minimum width to maintain structure */
        }

        .gallery-item.enlarged .gallery-image {
            position: fixed;
            left: 50%;
            top: 50%;
            transform: translate(-50%, -50%) scale(1);
            max-width: 90vw;
            max-height: 90vh;
            width: auto;
            height: auto;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.8);
            z-index: 1000;
        }

        .gallery-image {
            width: 200%; /* Set width to double */
            height: auto; /* Keep height auto for aspect ratio */
            display: block;
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


        document.addEventListener('DOMContentLoaded', function () {
            const galleryItems = document.querySelectorAll('.gallery-item');

            galleryItems.forEach(item => {
                item.addEventListener('click', function () {
                    // Remove enlarged class from all items
                    galleryItems.forEach(otherItem => {
                        if (otherItem !== this) {
                            otherItem.classList.remove('enlarged');
                        }
                    });
                    // Toggle enlarged class on clicked item
                    this.classList.toggle('enlarged');
                });
            });
        });
    </script>
@endsection