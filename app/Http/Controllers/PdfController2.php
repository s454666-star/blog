<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class PdfController2 extends Controller
{
    // 顯示上傳表單
    public function showUploadForm()
    {
        return view('pdf.upload2');  // 指向 resources/views/pdf/upload2.blade.php
    }

    // 處理PDF文件上傳並提取文字
    public function extractText(Request $request)
    {
        $request->validate([
            'pdf' => 'required|file|mimes:pdf|max:5000'
        ]);

        $pdfFile = $request->file('pdf');
        $pdfPath = $pdfFile->path();

        // 使用外部命令處理 PDF
        $process = new Process(['pdftotext', '-layout', $pdfPath, '-']);
        $process->run();

        // 檢查命令執行是否成功
        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        // 保存文本到文件
//        Storage::put('pdf_output.txt', $process->getOutput());

        $text = str_replace("\n", "\r\n", $process->getOutput());

        return view('pdf.text', compact('text'));  // 確保有一個叫做 'pdf.display' 的視圖
    }
}
