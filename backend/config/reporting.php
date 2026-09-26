<?php

return [

    /*
    | Map tiles for the monitoring map (master doc §31). Empty = no background tiles (the
    | locations are still drawn). The default is OpenStreetMap; for production, use a tile
    | server whose usage policy fits the expected traffic.
    */
    'map_tile_url' => env('REPORTING_MAP_TILE_URL', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),
    'map_attribution' => env('REPORTING_MAP_ATTRIBUTION', '© OpenStreetMap contributors'),

    // Maximum days in one report request (larger periods: several exports).
    'max_range_days' => (int) env('REPORTING_MAX_RANGE_DAYS', 366),

    // Rows shown in the on-screen preview; the export always contains everything.
    'preview_rows' => 100,
];
