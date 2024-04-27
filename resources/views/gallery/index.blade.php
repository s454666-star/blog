<?php

@foreach ($finder as $file)
    <img src="{{ asset($file->getRelativePathname()) }}" alt="{{ $file->getFilename() }}">
@endforeach
