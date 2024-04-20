<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class PdfController2 extends Controller
{
    // 顯示上傳表單
    public function showUploadForm()
    {
        return view('upload2');
    }

    // 處理PDF文件上傳並提取文字
    public function extractText(Request $request)
    {
        $request->validate([
            'pdf' => 'required|file|mimes:pdf|max:5000'
        ]);

        $pdfFile = $request->file('pdf');
        $pdfPath = $pdfFile->path();

        // 使用 pdftotext 轉換 PDF 文本
        $process = new Process(['pdftotext', '-layout', $pdfPath, '-']);
        $process->run();

        // 執行過程中發生錯誤
        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        // 取得文本並將換行符 \n 替換為 \r\n
        $text = str_replace("\n", "\r\n", $process->getOutput());

        return view('pdf.text', compact('text'));
    }
}
