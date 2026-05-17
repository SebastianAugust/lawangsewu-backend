<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Kirim rekap penjualan harian ke owner setiap hari jam 22:00 WIB.
// Owner phone & token diambil dari .env (FONNTE_OWNER_PHONE, FONNTE_TOKEN).
Schedule::command('reports:send-daily')
    ->dailyAt('22:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping()
    ->onOneServer();
