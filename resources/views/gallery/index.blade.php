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
            overflow: hidden;
            position: relative;
        }

        .gallery {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
            gap: 5px;
            justify-items: center;
            align-items: center;
            position: relative;
        }

        .gallery-item {
            position: relative;
            overflow: hidden;
            cursor: pointer;
            transition: transform 0.3s ease;
        }

        .gallery-item.enlarged .gallery-image {
            position: fixed;
            left: 50%;
            top: 50%;
            transform: translate(-50%, -50%) scale(1.5);
            max-width: 90vw;
            max-height: 90vh;
            width: auto;
            height: auto;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.8);
            z-index: 1000;
        }

        .gallery-image {
            width: 100%;
            height: auto;
            display: block;
            transition: transform 0.3s ease;
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