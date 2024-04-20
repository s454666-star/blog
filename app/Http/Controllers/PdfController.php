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

        $text = (new Pdf())
            ->setPdf($pdfPath)
            ->text();

        return view('pdf.text', compact('text'));
    }
}
