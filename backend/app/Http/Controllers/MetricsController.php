<?php

namespace App\Http\Controllers;

use App\Metrics\MetricRegistry;
use Illuminate\Http\Response;

class MetricsController extends Controller
{
    public function __invoke(MetricRegistry $metrics): Response
    {
        // Prometheus 用 Content-Type 判斷版本，寫錯它會拒收整份內容。
        return response($metrics->render(), 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
        ]);
    }
}
