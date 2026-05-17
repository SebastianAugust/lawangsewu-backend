<?php

namespace App\Services;

use App\Models\Menu;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;

class ReportService
{
    // Single source of truth — every report shape (daily, weekly, monthly)
    // is built from this. Pure-SQL aggregation: no per-row PHP work, no N+1.
    public function aggregate(string $from, string $to): array
    {
        $start = $from . ' 00:00:00';
        $end   = $to   . ' 23:59:59';

        $orderStats = Order::whereBetween('created_at', [$start, $end])
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
            ->select('payment_method',
                DB::raw('COUNT(*) as count'),
                DB::raw('COALESCE(SUM(total_price), 0) as total'))
            ->groupBy('payment_method')
            ->orderByDesc('total')
            ->get();

        $hourlySales = Order::whereBetween('created_at', [$start, $end])
            ->where('status', 'completed')
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

        return [
            'period_start'      => $from,
            'period_end'        => $to,
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
        ];
    }

    public function periodTotals(string $from, string $to): array
    {
        $row = Order::whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->where('status', 'completed')
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(total_price), 0) as total')
            ->first();

        return [
            'orders'  => (int) ($row?->cnt   ?? 0),
            'revenue' => (int) ($row?->total ?? 0),
        ];
    }
}
