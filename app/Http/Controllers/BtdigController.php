<?php

    namespace App\Http\Controllers;

    use Illuminate\Support\Facades\DB;

    class BtdigController extends Controller
    {
        public function index()
        {
            $results = DB::select("
            SELECT *
            FROM btdig_results
            ORDER BY
                CAST(SUBSTRING(search_keyword, 2) AS UNSIGNED) ASC,
                CAST(REPLACE(size, ' GB', '') AS DECIMAL(10,2)) ASC
        ");

            return view('btdig.index', [
                'results' => $results
            ]);
        }
    }
