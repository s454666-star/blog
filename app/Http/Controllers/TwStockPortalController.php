<?php

namespace App\Http\Controllers;

use App\Support\TwStockPages;
use Illuminate\Contracts\View\View;

class TwStockPortalController extends Controller
{
    public function index(): View
    {
        return view('tw-stock.portal', [
            'pages' => TwStockPages::all(),
        ]);
    }
}
