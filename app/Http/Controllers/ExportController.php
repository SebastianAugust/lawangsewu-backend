<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;
use Laravel\Sanctum\PersonalAccessToken;

class ExportController extends Controller
{
    private function authenticateFromToken(Request $request)
{
    $token = $request->get('token');
    if (!$token) {
        abort(401, json_encode(['message' => 'Token required']));
    }

    $personalToken = PersonalAccessToken::findToken($token);
    if (!$personalToken) {
        abort(401, json_encode(['message' => 'Invalid token']));
    }

    return $personalToken->tokenable;
}

    public function dailyPdf(Request $request)
    {
        $this->authenticateFromToken($request);
        $date = $request->get('date', now()->toDateString());
        $data = $this->getDailyData($date);

        $pdf = Pdf::loadView('exports.daily-report', $data);
        $pdf->setPaper([0, 0, 595.28, 841.89]);

        return $pdf->download("laporan-harian-{$date}.pdf");
    }

    public function monthlyPdf(Request $request)
    {
        $this->authenticateFromToken($request);
        $month = $request->get('month', now()->month);
        $year = $request->get('year', now()->year);
        $data = $this->getMonthlyData($month, $year);

        $pdf = Pdf::loadView('exports.monthly-report', $data);
        $pdf->setPaper([0, 0, 595.28, 841.89]);

        return $pdf->download("laporan-bulanan-{$year}-{$month}.pdf");
    }

    public function dailyCsv(Request $request)
    {
        $this->authenticateFromToken($request);
        $date = $request->get('date', now()->toDateString());
        $data = $this->getDailyData($date);

        $csv = "LAPORAN HARIAN - LAWANG SEWU\n";
        $csv .= "Tanggal: {$date}\n";
        $csv .= "Total Pendapatan: Rp " . number_format($data['total_revenue'], 0, ',', '.') . "\n";
        $csv .= "Total Pesanan: {$data['total_orders']}\n\n";

        $csv .= "No,Menu,Kategori,Terjual,Pendapatan\n";
        $no = 1;
        foreach ($data['menu_sales'] as $item) {
            $csv .= "{$no},{$item->menu->name},{$item->menu->category->name},{$item->total_sold}," . number_format($item->total_revenue, 0, ',', '.') . "\n";
            $no++;
        }

        $csv .= "\nNo,Order ID,Customer,Waktu,Metode,Total,Status\n";
        $no = 1;
        foreach ($data['orders'] as $order) {
            $customer = $order->customer_name ?: '-';
            $time = date('H:i', strtotime($order->created_at));
            $csv .= "{$no},{$order->id},{$customer},{$time},{$order->payment_method}," . number_format($order->total_price, 0, ',', '.') . ",{$order->status}\n";
            $no++;
        }

        return response($csv)
            ->header('Content-Type', 'text/csv')
            ->header('Content-Disposition', "attachment; filename=laporan-harian-{$date}.csv");
    }

    public function monthlyCsv(Request $request)
    {
        $this->authenticateFromToken($request);
        $month = $request->get('month', now()->month);
        $year = $request->get('year', now()->year);
        $data = $this->getMonthlyData($month, $year);

        $monthName = date('F', mktime(0, 0, 0, $month, 1));

        $csv = "LAPORAN BULANAN - LAWANG SEWU\n";
        $csv .= "Bulan: {$monthName} {$year}\n";
        $csv .= "Total Pendapatan: Rp " . number_format($data['total_revenue'], 0, ',', '.') . "\n";
        $csv .= "Total Pesanan: {$data['total_orders']}\n";
        $csv .= "Rata-rata/Hari: Rp " . number_format($data['avg_daily'], 0, ',', '.') . "\n\n";

        $csv .= "Tanggal,Pesanan,Pendapatan\n";
        foreach ($data['daily_revenue'] as $day) {
            $csv .= "{$day->date},{$day->orders}," . number_format($day->revenue, 0, ',', '.') . "\n";
        }

        $csv .= "\nNo,Menu,Terjual,Pendapatan\n";
        $no = 1;
        foreach ($data['top_menus'] as $item) {
            $csv .= "{$no},{$item->menu->name},{$item->total_sold}," . number_format($item->total_revenue, 0, ',', '.') . "\n";
            $no++;
        }

        return response($csv)
            ->header('Content-Type', 'text/csv')
            ->header('Content-Disposition', "attachment; filename=laporan-bulanan-{$year}-{$month}.csv");
    }

    private function getDailyData($date)
    {
        $orders = Order::with('items.menu', 'user')
            ->whereDate('created_at', $date)
            ->orderBy('created_at', 'desc')
            ->get();

        $totalRevenue = $orders->where('status', 'completed')->sum('total_price');
        $totalOrders = $orders->where('status', 'completed')->count();
        $totalVoided = $orders->where('status', 'voided')->count();

        $paymentBreakdown = Order::whereDate('created_at', $date)
            ->where('status', 'completed')
            ->select('payment_method', DB::raw('COUNT(*) as count'), DB::raw('SUM(total_price) as total'))
            ->groupBy('payment_method')
            ->get();

        $menuSales = OrderItem::whereHas('order', function ($q) use ($date) {
            $q->whereDate('created_at', $date)->where('status', 'completed');
        })
            ->select('menu_id', DB::raw('SUM(quantity) as total_sold'), DB::raw('SUM(subtotal) as total_revenue'))
            ->groupBy('menu_id')
            ->with('menu.category')
            ->orderByDesc('total_sold')
            ->get();

        return [
            'date' => $date,
            'total_revenue' => $totalRevenue,
            'total_orders' => $totalOrders,
            'total_voided' => $totalVoided,
            'payment_breakdown' => $paymentBreakdown,
            'menu_sales' => $menuSales,
            'orders' => $orders,
        ];
    }

    private function getMonthlyData($month, $year)
    {
        $dailyRevenue = Order::whereMonth('created_at', $month)
            ->whereYear('created_at', $year)
            ->where('status', 'completed')
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('SUM(total_price) as revenue'), DB::raw('COUNT(*) as orders'))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $totalRevenue = $dailyRevenue->sum('revenue');
        $totalOrders = $dailyRevenue->sum('orders');
        $avgDaily = $dailyRevenue->count() > 0 ? round($totalRevenue / $dailyRevenue->count()) : 0;

        $topMenus = OrderItem::whereHas('order', function ($q) use ($month, $year) {
            $q->whereMonth('created_at', $month)
                ->whereYear('created_at', $year)
                ->where('status', 'completed');
        })
            ->select('menu_id', DB::raw('SUM(quantity) as total_sold'), DB::raw('SUM(subtotal) as total_revenue'))
            ->groupBy('menu_id')
            ->with('menu')
            ->orderByDesc('total_sold')
            ->get();

        return [
            'month' => $month,
            'year' => $year,
            'month_name' => date('F', mktime(0, 0, 0, $month, 1)),
            'total_revenue' => $totalRevenue,
            'total_orders' => $totalOrders,
            'avg_daily' => $avgDaily,
            'daily_revenue' => $dailyRevenue,
            'top_menus' => $topMenus,
        ];
    }
}
