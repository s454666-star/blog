<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Google\Cloud\Storage\StorageClient;

class UploadStorageController extends Controller
{
    public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required|file',
        ]);

        $file = $request->file('file');
        $filePath = $file->getPathName();
        $fileName = $file->getClientOriginalName();

        $storage = new StorageClient([
            'projectId' => env('GOOGLE_CLOUD_PROJECT_ID'),
            'keyFilePath' => env('GOOGLE_CLOUD_KEY_FILE'),
        ]);

        $bucket = $storage->bucket(env('GOOGLE_CLOUD_STORAGE_BUCKET'));
        $bucket->upload(fopen($filePath, 'r'), [
            'name' => $fileName
        ]);

        return response()->json(['success' => 'File uploaded successfully']);
    }
}
