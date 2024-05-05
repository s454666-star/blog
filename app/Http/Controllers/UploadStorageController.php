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
            'keyFile' => [
                'type' => 'service_account',
                'project_id' => env('GOOGLE_CLOUD_PROJECT_ID'),
                'private_key_id' => env('GOOGLE_CLOUD_HMAC_ACCESS_KEY'),
                'private_key' => env('GOOGLE_CLOUD_HMAC_SECRET_KEY'),
                'client_email' => 's4546664234@stellar-day-420712.iam.gserviceaccount.com',
                'client_id' => '',
                'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
                'token_uri' => 'https://oauth2.googleapis.com/token',
                'auth_provider_x509_cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
                'client_x509_cert_url' => ''
            ]
        ]);

        $bucket = $storage->bucket(env('GOOGLE_CLOUD_STORAGE_BUCKET'));
        $bucket->upload(fopen($filePath, 'r'), [
            'name' => $fileName
        ]);

        return response()->json(['success' => 'File uploaded successfully']);
    }
}
