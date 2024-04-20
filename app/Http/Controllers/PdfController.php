<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Spatie\PdfToText\Pdf;

class PdfController extends Controller
{
    // 顯示上傳表單
    public function showUploadForm()
    {
        return view('pdf.upload');
    }

    // 處理PDF文件上傳並提取文字
    public function extractText(Request $request)
    {
        $request->validate([
            'pdf' => 'required|file|mimes:pdf|max:5000'
        ]);

        $pdfFile = $request->file('pdf');
        $pdfPath = $pdfFile->path();

        // 使用 Spatie PDF 轉換庫提取文本
        $text = (new Pdf())
            ->setPdf($pdfPath)
            ->text();

        // 確保文本中的換行符不被修改（如果 Spatie 库沒有保留，您可以手動處理）
        // 這裡的假設是文本使用 \n 作為換行符
        $text = str_replace("\n", "\r\n", $text);

        return view('pdf.text', compact('text'));
    }
}
