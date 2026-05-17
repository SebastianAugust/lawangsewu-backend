<?php

namespace App\Console\Commands;

use App\Services\FonnteService;
use App\Services\ReportService;
use Illuminate\Console\Command;

class SendDailyRecapCommand extends Command
{
    protected $signature = 'reports:send-daily
                            {--date= : Tanggal rekap (Y-m-d). Default: hari ini}
                            {--phone= : Override nomor tujuan. Default: FONNTE_OWNER_PHONE}';

    protected $description = 'Kirim rekap penjualan harian ke owner via Fonnte (WhatsApp)';

    public function handle(ReportService $reports, FonnteService $fonnte): int
    {
        $date  = $this->option('date') ?: now()->toDateString();
        $phone = $this->option('phone') ?: config('services.fonnte.owner_phone');

        if (empty($phone)) {
            $this->error('FONNTE_OWNER_PHONE tidak diset di .env');
            return self::FAILURE;
        }

        $report    = $reports->aggregate($date, $date);
        $yesterday = date('Y-m-d', strtotime($date . ' -1 day'));
        $prev      = $reports->periodTotals($yesterday, $yesterday);

        $message = $this->buildMessage($date, $report, $prev);

        $this->info("Mengirim rekap {$date} ke {$phone}...");
        $ok = $fonnte->send($phone, $message);

        if (! $ok) {
            $this->error('Gagal mengirim rekap (cek storage/logs/laravel.log)');
            return self::FAILURE;
        }

        $this->info('Rekap terkirim.');
        return self::SUCCESS;
    }

    private function buildMessage(string $date, array $report, array $prev): string
    {
        $rp = fn ($n) => 'Rp ' . number_format((int) $n, 0, ',', '.');

        $dateLabel = \Illuminate\Support\Carbon::parse($date)
            ->locale('id')
            ->translatedFormat('l, d F Y');

        $lines = [];
        $lines[] = '*LAWANG SEWU — REKAP HARIAN*';
        $lines[] = "_{$dateLabel}_";
        $lines[] = '';

        if ((int) $report['total_orders'] === 0) {
            $lines[] = 'Belum ada transaksi hari ini.';
            return implode("\n", $lines);
        }

        $avg = $report['total_orders'] > 0
            ? (int) round($report['total_revenue'] / $report['total_orders'])
            : 0;

        $lines[] = '*Ringkasan*';
        $lines[] = '• Pendapatan : ' . $rp($report['total_revenue']);
        $lines[] = '• Pesanan    : ' . $report['total_orders'];
        $lines[] = '• Rata-rata  : ' . $rp($avg) . ' / pesanan';
        $lines[] = '• Void       : ' . $report['total_voided'];

        // Comparison vs kemarin
        if ($prev['revenue'] > 0) {
            $diff = $report['total_revenue'] - $prev['revenue'];
            $pct  = (int) round(($diff / $prev['revenue']) * 100);
            $arrow = $diff >= 0 ? '↑' : '↓';
            $lines[] = '• vs kemarin : ' . $arrow . ' ' . abs($pct) . '% (' . $rp($prev['revenue']) . ')';
        } elseif ($prev['orders'] === 0) {
            $lines[] = '• vs kemarin : tidak ada transaksi kemarin';
        }
        $lines[] = '';

        // Top menu (max 5)
        $topMenus = collect($report['top_menus'])->take(5);
        if ($topMenus->isNotEmpty()) {
            $lines[] = '*Top Menu*';
            foreach ($topMenus as $i => $m) {
                $name = $m->menu?->name ?? 'Tanpa Nama';
                $lines[] = ($i + 1) . '. ' . $name . ' — ' . $m->total_sold . ' pcs (' . $rp($m->total_revenue) . ')';
            }
            $lines[] = '';
        }

        // Kategori
        $cats = collect($report['category_sales']);
        if ($cats->isNotEmpty()) {
            $lines[] = '*Per Kategori*';
            foreach ($cats as $c) {
                $lines[] = '• ' . $c->category . ' : ' . $rp($c->total_revenue);
            }
            $lines[] = '';
        }

        // Pembayaran
        $pays = collect($report['payment_breakdown']);
        if ($pays->isNotEmpty()) {
            $lines[] = '*Pembayaran*';
            foreach ($pays as $p) {
                $method = strtoupper($p->payment_method);
                $lines[] = '• ' . $method . ' : ' . $rp($p->total) . ' (' . $p->count . 'x)';
            }
            $lines[] = '';
        }

        // Jam tersibuk
        $peak = collect($report['hourly_sales'])->sortByDesc('orders')->first();
        if ($peak && $peak['orders'] > 0) {
            $lines[] = '*Jam Tersibuk* : ' . str_pad((string) $peak['hour'], 2, '0', STR_PAD_LEFT) . ':00 (' . $peak['orders'] . ' pesanan)';
        }

        return implode("\n", $lines);
    }
}
