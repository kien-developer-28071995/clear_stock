<?php

namespace App\Http\Controllers;

use App\Reports\LedgerSync;
use App\Reports\Overview;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class OverviewController extends Controller
{
    public function __invoke(Overview $overview): View
    {
        return view('overview', $overview->build());
    }

    /** "Refresh now": read the app's shops again instead of waiting for the schedule. */
    public function sync(LedgerSync $sync): RedirectResponse
    {
        $stats = $sync->run();

        return back()->with('status', "Refreshed: {$stats['shops']} shops read, {$stats['installed']} new, {$stats['uninstalled']} uninstalled, {$stats['plan_changed']} plan changes.");
    }
}
