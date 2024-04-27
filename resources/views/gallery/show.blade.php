@extends('layouts.app')

@section('content')
    <div class="image-view">
        <img src="{{ asset('storage/' . $filename) }}" alt="Full-size Image">
        <br>
        <a href="{{ route('gallery.index') }}">Return to Gallery</a>
    </div>
@endsection
