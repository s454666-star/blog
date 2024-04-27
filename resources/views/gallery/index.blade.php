@extends('layouts.app')

@section('head')
    <style>
        body {
            background-color: #f4f4f4;
            color: #333;
            font-family: 'Arial', sans-serif;
        }

        .frame {
            max-width: 100vw; /* Allow maximum width of the viewport */
            width: auto; /* Adjust width based on content size */
            margin: 20px auto; /* Center the frame horizontally */
            border: 10px solid #8B4513; /* Thick solid border to mimic a wooden frame */
            padding: 30px;
            background: #FFF;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.5); /* Shadow for depth */
            overflow: visible; /* Allow overflow to be visible */
            position: relative; /* Ensures proper stacking context */
        }

        .gallery {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(100px, 1fr)); /* Responsive grid layout */
            gap: 5px;
            justify-items: center;
            align-items: center;
            position: relative;
        }

        .gallery-item {
            transition: transform 0.6s ease; /* Smooth transition for transform */
            cursor: pointer; /* Indicates that the item can be interacted with */
            position: relative; /* Ensures proper stacking and positioning */
            overflow: visible; /* Changed to visible to allow images to expand on hover */
            z-index: 1; /* Lower z-index for non-hovered items */
        }

        .gallery-item:hover {
            z-index: 1000; /* Very high z-index to ensure the item is displayed above all other content */
            overflow: visible; /* Allow the hovered image to be visible */
        }

        .gallery-item:hover .gallery-image {
            transform: translate(-50%, -50%) scale(1.5); /* Center and scale the image */
            position: fixed; /* Fixed position to keep it relative to the viewport */
            left: 50%; /* Center horizontally */
            top: 50%; /* Center vertically */
            max-width: 90vw; /* Limits the width to 90% of the viewport width */
            max-height: 90vh; /* Limits the height to 90% of the viewport height */
            width: auto; /* Maintains aspect ratio */
            height: auto; /* Maintains aspect ratio */
            box-shadow: 0 0 10px rgba(0,0,0,0.8); /* Optional: Adds shadow for better visibility */
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