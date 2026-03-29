<?php

namespace App\Http\Controllers;

use App\Services\DialogueTokenStatsDashboardService;
use Illuminate\Contracts\View\View;

class DialogueTokenStatsController extends Controller
{
    public function __invoke(DialogueTokenStatsDashboardService $dashboardService): View
    {
        return view('dialogues.token-stats', $dashboardService->getDashboard());
    }
}
