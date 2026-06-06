<?php

namespace Database\Seeders;

use App\Models\Menu;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Generates a rich, realistic order history so the owner dashboard has
 * something to show across every view: daily (today + yesterday trend),
 * weekly, monthly (this month + last month trend), per-cabang comparison,
 * busy-hour patterns, category / payment / variant breakdowns and voids.
 *
 * Orders span ~9 weeks up to today, split across both cabang with branch 1
 * busier than branch 2 so the comparison cards differ. Re-runnable: it
 * clears existing orders first for a clean, reproducible demo dataset.
 */
class DashboardDemoSeeder extends Seeder
{
    public function run(): void
    {
        $kasirs = User::where('role', 'kasir')->whereNotNull('branch_id')->get();
        $owner  = User::where('role', 'owner')->first();

        if ($kasirs->isEmpty()) {
            $this->command->warn('DashboardDemoSeeder: no branch kasir found, run BranchSeeder + UserSeeder first.');
            return;
        }

        $menus = Menu::with('variants')->get();
        if ($menus->isEmpty()) {
            $this->command->warn('DashboardDemoSeeder: no menus found, run MenuSeeder first.');
            return;
        }

        // Clean slate so the demo is reproducible and totals stay realistic.
        OrderItem::query()->delete();
        Order::query()->delete();

        $payments = ['cash', 'cash', 'cash', 'qris', 'qris', 'transfer']; // ~50/33/17
        // Busy hours weighted toward lunch & dinner service.
        $hours = [10, 11, 11, 12, 12, 12, 13, 13, 14, 15, 16, 17, 18, 18, 19, 19, 19, 20, 20, 21];

        $start = now()->copy()->subDays(63)->startOfDay();
        $today = now()->copy()->startOfDay();

        $totalOrders = 0;
        $totalVoided = 0;

        foreach ($kasirs as $i => $kasir) {
            // Branch 1 (first kasir) busier than branch 2.
            $busyFactor = $i === 0 ? 1.0 : 0.7;

            for ($day = $start->copy(); $day <= $today; $day->addDay()) {
                $isWeekend = in_array((int) $day->format('N'), [6, 7], true);
                $base      = $isWeekend ? 9 : 6;
                $count     = (int) round(($base + mt_rand(-2, 4)) * $busyFactor);
                $count     = max(1, $count);

                for ($n = 0; $n < $count; $n++) {
                    $items = $this->randomItems($menus);
                    if (empty($items)) {
                        continue;
                    }

                    $total   = array_sum(array_column($items, 'subtotal'));
                    $payment = $payments[array_rand($payments)];
                    $cash    = null;
                    $change  = null;
                    if ($payment === 'cash') {
                        $cash   = (int) (ceil($total / 5000) * 5000) + (mt_rand(0, 4) * 5000);
                        $change = $cash - $total;
                    }

                    $hour = $hours[array_rand($hours)];
                    $when = $day->copy()->setTime($hour, mt_rand(0, 59), mt_rand(0, 59));

                    // ~4% of orders end up voided (skip today so live demo is clean).
                    $voided = $day->lt($today) && mt_rand(1, 100) <= 4;

                    $order = Order::create([
                        'user_id'        => $kasir->id,
                        'branch_id'      => $kasir->branch_id,
                        'customer_name'  => $this->randomName(),
                        'total_price'    => $total,
                        'payment_method' => $payment,
                        'cash_received'  => $cash,
                        'change_amount'  => $change,
                        'status'         => $voided ? 'voided' : 'completed',
                        'void_reason'    => $voided ? 'Pesanan dibatalkan pelanggan' : null,
                        'voided_by'      => $voided ? $owner?->id : null,
                        'voided_at'      => $voided ? $when->copy()->addMinutes(30) : null,
                        'created_at'     => $when,
                        'updated_at'     => $when,
                    ]);

                    foreach ($items as $item) {
                        OrderItem::create(array_merge(['order_id' => $order->id], $item));
                    }

                    $totalOrders++;
                    if ($voided) {
                        $totalVoided++;
                    }
                }
            }
        }

        $this->command->info(
            "DashboardDemoSeeder: {$totalOrders} pesanan dibuat lintas " .
            "{$kasirs->count()} cabang (~9 minggu, {$totalVoided} void)."
        );
    }

    /** 1–4 line items, each a random menu (random variant when it has any). */
    private function randomItems($menus): array
    {
        $out   = [];
        $picks = $menus->random(min($menus->count(), mt_rand(1, 4)));

        foreach ($picks as $menu) {
            $variant = $menu->variants->isNotEmpty() ? $menu->variants->random() : null;
            $price   = $variant?->price ?? $menu->price ?? 0;
            if ($price <= 0) {
                continue;
            }
            $qty = mt_rand(1, 3);
            $out[] = [
                'menu_id'         => $menu->id,
                'menu_variant_id' => $variant?->id,
                'variant_name'    => $variant?->name,
                'quantity'        => $qty,
                'subtotal'        => $price * $qty,
            ];
        }

        return $out;
    }

    private function randomName(): ?string
    {
        $names = [
            'Andi', 'Sari', 'Budi', 'Citra', 'Dewi', 'Eka', 'Fajar', 'Gita',
            'Hadi', 'Indah', 'Joko', 'Kiki', 'Lina', 'Maya', 'Nanda', 'Oki',
            null, null, // some walk-in orders have no name
        ];

        return $names[array_rand($names)];
    }
}
