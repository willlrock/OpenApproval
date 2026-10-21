<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SystemLogReader;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SystemLogsController extends Controller
{
    public function __invoke(Request $request, SystemLogReader $reader)
    {
        $filters = $request->validate([
            'level' => 'nullable|in:DEBUG,INFO,NOTICE,WARNING,ERROR,CRITICAL,ALERT,EMERGENCY',
            'date' => 'nullable|date_format:Y-m-d',
            'search' => 'nullable|string|max:200',
        ]);
        $secrets = [config('app.key'), config('nutgram.token')];
        foreach (config('database.connections', []) as $connection) {
            $secrets[] = $connection['password'] ?? null;
        }

        return Inertia::render('Dashboard/SystemLogs', [
            ...$reader->readConfigured(config('logging'), $filters, $secrets),
            'filters' => $filters,
        ])->toResponse($request)->header('Cache-Control', 'no-store, private');
    }
}
