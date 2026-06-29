<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Menu;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;

class ReportService
{
    // Single source of truth — every report shape (daily, weekly, monthly)
    // is built from this. Pure-SQL aggregation: no per-row PHP work, no N+1.
    // When $branchId is given, every query is scoped to that one cabang;
    // null means all cabang combined (owner "Semua Cabang" view).
    public function aggregate(string $from, string $to, ?int $branchId = null): array
    {
        $start = $from . ' 00:00:00';
        $end   = $to   . ' 23:59:59';

        $branchFilter = fn ($q) => $q->when(
            $branchId,
            fn ($qq) => $qq->where('orders.branch_id', $branchId),
        );

        $orderStats = Order::whereBetween('created_at', [$start, $end])
            ->tap($branchFilter)
            ->select('status',
                DB::raw('COUNT(*) as cnt'),
                DB::raw('COALESCE(SUM(total_price), 0) as total'))
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $totalRevenue = (int) ($orderStats['completed']->total ?? 0);
        $totalOrders  = (int) ($orderStats['completed']->cnt   ?? 0);
        $totalVoided  = (int) ($orderStats['voided']->cnt      ?? 0);

        $paymentBreakdown = Order::whereBetween('created_at', [$start, $end])
            ->where('status', 'completed')
            ->tap($branchFilter)
            ->select('payment_method',
                DB::raw('COUNT(*) as count'),
                DB::raw('COALESCE(SUM(total_price), 0) as total'))
            ->groupBy('payment_method')
            ->orderByDesc('total')
            ->get();

        $hourlySales = Order::whereBetween('created_at', [$start, $end])
            ->where('status', 'completed')
            ->tap($branchFilter)
            ->select(
                DB::raw('HOUR(created_at) as hour'),
                DB::raw('COUNT(*) as orders'),
                DB::raw('COALESCE(SUM(total_price), 0) as revenue'))
            ->groupBy(DB::raw('HOUR(created_at)'))
            ->orderBy('hour')
            ->get()
            ->map(fn ($r) => [
                'hour'    => (int) $r->hour,
                'orders'  => (int) $r->orders,
                'revenue' => (int) $r->revenue,
            ]);

        $dailyRows = Order::whereBetween('created_at', [$start, $end])
            ->where('status', 'completed')
            ->tap($branchFilter)
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as orders'),
                DB::raw('COALESCE(SUM(total_price), 0) as revenue'))
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $dailyRevenue = [];
        $cursor = new \DateTime($from);
        $endDt  = new \DateTime($to);
        while ($cursor <= $endDt) {
            $key = $cursor->format('Y-m-d');
            $row = $dailyRows->get($key);
            $dailyRevenue[] = [
                'date'    => $key,
                'orders'  => (int) ($row->orders  ?? 0),
                'revenue' => (int) ($row->revenue ?? 0),
            ];
            $cursor->modify('+1 day');
        }

        $daysInRange = count($dailyRevenue);
        $avgDaily    = $daysInRange > 0 ? (int) round($totalRevenue / $daysInRange) : 0;

        $bestDay = collect($dailyRevenue)->sortByDesc('revenue')->first();
        if (! $bestDay || $bestDay['revenue'] === 0) $bestDay = null;
        $worstDay = collect($dailyRevenue)
            ->filter(fn ($d) => $d['revenue'] > 0)
            ->sortBy('revenue')
            ->first() ?: null;

        $menuSales = OrderItem::query()
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->whereBetween('orders.created_at', [$start, $end])
            ->where('orders.status', 'completed')
            ->tap($branchFilter)
            ->select('order_items.menu_id',
                DB::raw('SUM(order_items.quantity) as total_sold'),
                DB::raw('COALESCE(SUM(order_items.subtotal), 0) as total_revenue'))
            ->groupBy('order_items.menu_id')
            ->orderByDesc('total_sold')
            ->with('menu.category')
            ->get();

        $categorySales = OrderItem::query()
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('menus', 'order_items.menu_id', '=', 'menus.id')
            ->join('categories', 'menus.category_id', '=', 'categories.id')
            ->whereBetween('orders.created_at', [$start, $end])
            ->where('orders.status', 'completed')
            ->tap($branchFilter)
            ->select('categories.name as category',
                DB::raw('SUM(order_items.quantity) as total_sold'),
                DB::raw('COALESCE(SUM(order_items.subtotal), 0) as total_revenue'))
            ->groupBy('categories.name')
            ->orderByDesc('total_revenue')
            ->get();

        $variantRows = OrderItem::query()
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->whereBetween('orders.created_at', [$start, $end])
            ->where('orders.status', 'completed')
            ->tap($branchFilter)
            ->select('order_items.menu_id', 'order_items.variant_name',
                DB::raw('SUM(order_items.quantity) as qty'),
                DB::raw('COALESCE(SUM(order_items.subtotal), 0) as revenue'))
            ->groupBy('order_items.menu_id', 'order_items.variant_name')
            ->get();

        $menuLookup = Menu::with('category')
            ->whereIn('id', $variantRows->pluck('menu_id')->unique()->values())
            ->get()
            ->keyBy('id');

        $variantMap = [];
        foreach ($variantRows as $r) {
            $mid  = $r->menu_id;
            $menu = $menuLookup->get($mid);
            if (! isset($variantMap[$mid])) {
                $variantMap[$mid] = [
                    'menu_id'       => (int) $mid,
                    'menu_name'     => $menu?->name ?? 'Tanpa Nama',
                    'category_name' => $menu?->category?->name ?? '-',
                    'total_qty'     => 0,
                    'total_revenue' => 0,
                    'variants'      => [],
                ];
            }
            $variantMap[$mid]['total_qty']     += (int) $r->qty;
            $variantMap[$mid]['total_revenue'] += (int) $r->revenue;
            $variantMap[$mid]['variants'][] = [
                'name'    => $r->variant_name ?: '(tanpa varian)',
                'qty'     => (int) $r->qty,
                'revenue' => (int) $r->revenue,
            ];
        }
        foreach ($variantMap as &$m) {
            usort($m['variants'], fn ($a, $b) => $b['qty'] <=> $a['qty']);
        }
        unset($m);
        $variantBreakdown = array_values($variantMap);
        usort($variantBreakdown, fn ($a, $b) => $b['total_qty'] <=> $a['total_qty']);

        // Rekap per varian: dikelompokkan berdasarkan variant_name lintas menu
        // (mis. semua "Paha" dari menu apa pun), dengan breakdown kontribusi
        // tiap menu. Item tanpa varian (variant_name null/kosong) dikecualikan.
        $variantSummaryMap = [];
        foreach ($variantRows as $r) {
            $vname = $r->variant_name;
            if ($vname === null || $vname === '') {
                continue;
            }
            if (! isset($variantSummaryMap[$vname])) {
                $variantSummaryMap[$vname] = [
                    'variant_name'  => $vname,
                    'total_sold'    => 0,
                    'total_revenue' => 0,
                    'breakdown'     => [],
                ];
            }
            $variantSummaryMap[$vname]['total_sold']    += (int) $r->qty;
            $variantSummaryMap[$vname]['total_revenue'] += (int) $r->revenue;
            $variantSummaryMap[$vname]['breakdown'][] = [
                'menu_name' => $menuLookup->get($r->menu_id)?->name ?? 'Tanpa Nama',
                'sold'      => (int) $r->qty,
                'revenue'   => (int) $r->revenue,
            ];
        }
        foreach ($variantSummaryMap as &$v) {
            usort($v['breakdown'], fn ($a, $b) => $b['sold'] <=> $a['sold']);
        }
        unset($v);
        $variantSummary = array_values($variantSummaryMap);
        usort($variantSummary, fn ($a, $b) => $b['total_sold'] <=> $a['total_sold']);

        return [
            'period_start'      => $from,
            'period_end'        => $to,
            'branch_id'         => $branchId,
            'total_revenue'     => $totalRevenue,
            'total_orders'      => $totalOrders,
            'total_voided'      => $totalVoided,
            'avg_daily'         => $avgDaily,
            'best_day'          => $bestDay,
            'worst_day'         => $worstDay,
            'payment_breakdown' => $paymentBreakdown,
            'hourly_sales'      => $hourlySales,
            'daily_revenue'     => $dailyRevenue,
            'category_sales'    => $categorySales,
            'menu_sales'        => $menuSales,
            'top_menus'         => $menuSales->take(10)->values(),
            'variant_breakdown' => $variantBreakdown,
            'variant_summary'   => $variantSummary,
        ];
    }

    public function periodTotals(string $from, string $to, ?int $branchId = null): array
    {
        $row = Order::whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->where('status', 'completed')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(total_price), 0) as total')
            ->first();

        return [
            'orders'  => (int) ($row?->cnt   ?? 0),
            'revenue' => (int) ($row?->total ?? 0),
        ];
    }

    /**
     * Per-cabang totals for completed orders in the range — one row per
     * branch (including branches with zero orders), used by the owner's
     * "Semua Cabang" comparison cards on the dashboard.
     */
    public function branchBreakdown(string $from, string $to): array
    {
        $start = $from . ' 00:00:00';
        $end   = $to   . ' 23:59:59';

        $totals = Order::whereBetween('created_at', [$start, $end])
            ->where('status', 'completed')
            ->whereNotNull('branch_id')
            ->select('branch_id',
                DB::raw('COUNT(*) as orders'),
                DB::raw('COALESCE(SUM(total_price), 0) as revenue'))
            ->groupBy('branch_id')
            ->get()
            ->keyBy('branch_id');

        return Branch::orderBy('id')->get()->map(fn ($b) => [
            'branch_id'   => $b->id,
            'branch_name' => $b->name,
            'revenue'     => (int) ($totals[$b->id]->revenue ?? 0),
            'orders'      => (int) ($totals[$b->id]->orders  ?? 0),
        ])->all();
    }
}
