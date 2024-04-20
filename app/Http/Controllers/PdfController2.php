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

        $output = $process->getOutput();

        // 手動檢查換行符
        $text = str_replace("\n", "\r\n", $output);

        // 調試輸出
        file_put_contents('pdf_output.txt', $text); // 將處理後的文本保存至文件以進行檢查

        return view('pdf.text', compact('text'));
    }

}
