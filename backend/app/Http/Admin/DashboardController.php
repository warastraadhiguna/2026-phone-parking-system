<?php

namespace App\Http\Admin;

use App\Domain\Identity\Models\User;
use App\Domain\Reporting\Services\DashboardMetrics;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Landing page after login (master doc §29–§31). Shows the operational dashboard to
 * dashboard.operational and the executive dashboard to dashboard.executive; other roles see a
 * welcome card. ?view=executive switches for users who hold both.
 */
final class DashboardController
{
    public function __invoke(Request $request, DashboardMetrics $metrics): Response
    {
        /** @var User $user */
        $user = $request->user();
        $operational = $user->can('dashboard.operational');
        $executive = $user->can('dashboard.executive');
        $view = match (true) {
            $executive && (! $operational || $request->query('view') === 'executive') => 'executive',
            $operational => 'operational',
            default => null,
        };

        return Inertia::render('Home', [
            'view' => $view,
            'available' => ['operational' => $operational, 'executive' => $executive],
            'operational' => $view === 'operational' ? $metrics->operational() : null,
            'executive' => $view === 'executive' ? $metrics->executive() : null,
            'mapTileUrl' => config('reporting.map_tile_url'),
            'mapAttribution' => config('reporting.map_attribution'),
        ]);
    }
}
