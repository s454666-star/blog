<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Spatie\PdfToText\Pdf;

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

        $text = (new Pdf())
            ->setPdf($pdfPath)
            ->text();

        // 確保文本中的換行符不被修改
        $text = str_replace("\n", "\r\n", $text);

        return view('pdf.text', compact('text'));  // 請確保相應的視圖也已更新或存在
    }
}
