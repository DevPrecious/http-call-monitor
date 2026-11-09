<?php

namespace HttpCallMonitor\Http;

use HttpCallMonitor\CallRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class DashboardController
{
    private const WINDOWS = [1 => '1 hour', 6 => '6 hours', 24 => '24 hours', 168 => '7 days'];

    private const DEFAULT_WINDOW = 24;

    public function __invoke(Request $request, CallRepository $calls): View
    {
        $hours = $request->integer('hours', self::DEFAULT_WINDOW);

        if (! array_key_exists($hours, self::WINDOWS)) {
            $hours = self::DEFAULT_WINDOW;
        }

        return view('http-call-monitor::dashboard', [
            'hosts' => $calls->perHost($hours),
            'failures' => $calls->recentFailures($hours),
            'hours' => $hours,
            'windows' => self::WINDOWS,
        ]);
    }
}
