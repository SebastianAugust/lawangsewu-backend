<?php

namespace App\Http\Controllers;

use App\Services\ReportService;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    public function daily(Request $request)
    {
        $date   = $request->input('date', now()->toDateString());
        $report = $this->reports->aggregate($date, $date);

        $yesterday = date('Y-m-d', strtotime($date . ' -1 day'));
        $prev      = $this->reports->periodTotals($yesterday, $yesterday);

        return response()->json(array_merge($report, [
            'date'              => $date,
            'yesterday_revenue' => $prev['revenue'],
            'yesterday_orders'  => $prev['orders'],
        ]));
    }

    public function weekly(Request $request)
    {
        $date = $request->input('date', now()->toDateString());

        // Monday-anchored ISO week containing $date
        $d         = new \DateTime($date);
        $dayOfWeek = (int) $d->format('N'); // 1..7 (Mon..Sun)
        $monday    = (clone $d)->modify('-' . ($dayOfWeek - 1) . ' days');
        $sunday    = (clone $monday)->modify('+6 days');

        $weekStart = $monday->format('Y-m-d');
        $weekEnd   = $sunday->format('Y-m-d');

        $report = $this->reports->aggregate($weekStart, $weekEnd);

        $prevMonday = (clone $monday)->modify('-7 days');
        $prevSunday = (clone $prevMonday)->modify('+6 days');
        $prev       = $this->reports->periodTotals($prevMonday->format('Y-m-d'), $prevSunday->format('Y-m-d'));

        return response()->json(array_merge($report, [
            'week_start'        => $weekStart,
            'week_end'          => $weekEnd,
            'last_week_revenue' => $prev['revenue'],
            'last_week_orders'  => $prev['orders'],
        ]));
    }

    public function monthly(Request $request)
    {
        $month = (int) $request->input('month', now()->month);
        $year  = (int) $request->input('year',  now()->year);

        $start = sprintf('%04d-%02d-01', $year, $month);
        $end   = date('Y-m-t', strtotime($start));

        $report = $this->reports->aggregate($start, $end);

        $lastMonth     = $month === 1 ? 12        : $month - 1;
        $lastMonthYear = $month === 1 ? $year - 1 : $year;
        $lastStart     = sprintf('%04d-%02d-01', $lastMonthYear, $lastMonth);
        $lastEnd       = date('Y-m-t', strtotime($lastStart));
        $prev          = $this->reports->periodTotals($lastStart, $lastEnd);

        return response()->json(array_merge($report, [
            'month'               => $month,
            'year'                => $year,
            'last_month_revenue'  => $prev['revenue'],
            'last_month_orders'   => $prev['orders'],
        ]));
    }
}
