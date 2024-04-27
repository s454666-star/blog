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
    <div class="gallery">
        @foreach($selectedImages as $image)
            <a href="{{ route('gallery.show', basename($image)) }}" class="gallery-item">
                <img src="{{ asset($image) }}" alt="Gallery Image" class="gallery-image">
            </a>
        @endforeach
    </div>
@endsection

@section('scripts')
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const images = document.querySelectorAll('.gallery-image');
            images.forEach(image => {
                image.addEventListener('mouseover', () => {
                    image.style.opacity = 0.7;
                });
                image.addEventListener('mouseout', () => {
                    image.style.opacity = 1.0;
                });
            });
        });
    </script>
@endsection
