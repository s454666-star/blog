<?php

    namespace App\Http\Controllers;

    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\File;
    use Illuminate\Support\Facades\Storage;

    class EncryptionController extends Controller
    {
        private $iv = '1478523697539518'; // 固定 IV
        private $key = 'nobodyknowit';    // 密碼

        /**
         * 顯示加密/還原檔案頁面
         */
        public function index()
        {
            $storagePath = storage_path('app');
            $encryptedFolders = [];
            $restoredFiles = [];

            // 獲取加密後資料夾清單及明細
            foreach (scandir($storagePath) as $item) {
                $folderPath = $storagePath . '/' . $item;
                if (is_dir($folderPath) && str_contains($item, '_encrypted') && $item !== '.' && $item !== '..') {
                    $files = array_filter(scandir($folderPath), function ($file) use ($folderPath) {
                        return is_file($folderPath . '/' . $file) && $file !== '.gitignore';
                    });
                    $encryptedFolders[$item] = array_values($files);
                }
            }

            // 獲取還原後的檔案清單
            foreach (scandir($storagePath) as $item) {
                if (is_file($storagePath . '/' . $item) && !str_contains($item, '_encrypted') && $item !== '.gitignore') {
                    $restoredFiles[] = $item;
                }
            }

            return view('encrypt', compact('encryptedFolders', 'restoredFiles'));
        }


        public function downloadFolder(Request $request, $folder)
        {
            $folderPath = storage_path("app/{$folder}");
            if (!is_dir($folderPath)) {
                return back()->with('error', '資料夾不存在');
            }

            $zipFile = storage_path("app/{$folder}.zip");
            $zip = new \ZipArchive();
            if ($zip->open($zipFile, \ZipArchive::CREATE) === TRUE) {
                foreach (scandir($folderPath) as $file) {
                    if (is_file("{$folderPath}/{$file}")) {
                        $zip->addFile("{$folderPath}/{$file}", $file);
                    }
                }
                $zip->close();
            }

            return response()->download($zipFile)->deleteFileAfterSend(true);
        }

        public function downloadFile(Request $request, $file)
        {
            $filePath = storage_path("app/{$file}");
            if (!file_exists($filePath)) {
                return back()->with('error', '檔案不存在');
            }

            return response()->download($filePath);
        }


        /**
         * 加密與分割檔案
         */
        public function encrypt(Request $request)
        {
            $request->validate([
                'file' => 'required|file',
            ]);

            // 上傳的檔案
            $file = $request->file('file');
            $originalPath = $file->getPathname();
            $originalName = $file->getClientOriginalName();
            $destinationPath = storage_path("app/{$originalName}_encrypted");

            // 建立加密後的資料夾
            File::makeDirectory($destinationPath, 0755, true, true);

            // 讀取檔案內容並加密
            $data = file_get_contents($originalPath);
            $encryptedData = openssl_encrypt($data, 'aes-256-cbc', $this->key, OPENSSL_RAW_DATA, $this->iv);

            // 分割加密後的資料
            $chunks = str_split($encryptedData, 1024); // 每片 1KB
            foreach ($chunks as $index => $chunk) {
                file_put_contents("{$destinationPath}/chunk_{$index}.bin", $chunk);
            }

            return back()->with('success', '檔案加密成功，已存入：' . $destinationPath);
        }

        /**
         * 還原檔案
         */
        public function decrypt(Request $request)
        {
            $request->validate([
                'folder' => 'required|string',
            ]);

            $folderPath = storage_path("app/{$request->input('folder')}");
            if (!File::exists($folderPath)) {
                return back()->with('error', '資料夾不存在');
            }

            // 重組分段資料
            $files = File::files($folderPath);
            usort($files, fn($a, $b) => strcmp($a->getFilename(), $b->getFilename()));

            $encryptedData = '';
            foreach ($files as $file) {
                $encryptedData .= file_get_contents($file->getPathname());
            }

            // 解密還原
            $decryptedData = openssl_decrypt($encryptedData, 'aes-256-cbc', $this->key, OPENSSL_RAW_DATA, $this->iv);

            // 將還原的檔案存回原位置
            $originalFileName = basename($folderPath, '_encrypted');
            $restoredPath = storage_path("app/{$originalFileName}");
            file_put_contents($restoredPath, $decryptedData);

            return back()->with('success', '檔案還原成功，已存回：' . $restoredPath);
        }

        public function deleteFolder(Request $request, $folder)
        {
            $folderPath = storage_path("app/{$folder}");
            if (is_dir($folderPath)) {
                \File::deleteDirectory($folderPath);
                return back()->with('success', "已刪除整批資料夾：{$folder}");
            }
            return back()->with('error', "資料夾不存在：{$folder}");
        }

        public function deleteFile(Request $request, $file)
        {
            $filePath = storage_path("app/{$file}");
            if (is_file($filePath)) {
                \File::delete($filePath);
                return back()->with('success', "已刪除檔案：{$file}");
            }
            return back()->with('error', "檔案不存在：{$file}");
        }

    }
