<?php

@foreach ($images as $image)
    <div>
        <img src="{{ asset('storage/' . $image->path) }}" onclick="openImage('{{ asset('storage/' . $image->path) }}')">
    </div>
@endforeach
