<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Illuminate\Support\Collection;

class TextExport implements FromCollection, WithHeadings
{
    protected $text;

    public function __construct($text)
    {
        $this->text = $text;
    }

    public function collection()
    {
        return new Collection([
            ['content' => $this->text]
        ]);
    }

    public function headings(): array
    {
        return ['建物門牌'];
    }
}
