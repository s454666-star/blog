<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Spatie\PdfToText\Pdf;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\TextExport;

class PdfController extends Controller
{
    public function showUploadForm()
    {
        return view('pdf.upload');
    }

    public function extractText(Request $request)
    {
        $request->validate([
            'pdf' => 'required|file|mimes:pdf|max:5000',
        ]);

        $pdfFile = $request->file('pdf');
        $pdfPath = $pdfFile->path();
        $text = (new Pdf())->setPdf($pdfPath)->text();
        $text = str_replace("\n", "\r\n", $text);

        session()->put('extracted_text', $text); // 將提取的文本儲存到 session，以便後續導出 Excel

        return view('pdf.text', compact('text'));
    }

    // 導出 Excel 文件
    public function exportExcel()
    {
        $text = session()->get('extracted_text'); // 從 session 中獲取文本
        return Excel::download(new TextExport($text), 'extracted_text.xlsx');
    }
}
