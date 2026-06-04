<?php

namespace Database\Seeders;

use App\Models\Menu;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Database\Seeder;

class DummyOrderSeeder extends Seeder
{
    public function run(): void
    {
        // One kasir per branch so demo data is spread across cabang — this is
        // what makes the "Semua Cabang" comparison cards on the dashboard have
        // something to compare. Falls back to any user if no kasir exists.
        $kasirs = User::where('role', 'kasir')->whereNotNull('branch_id')->get();
        if ($kasirs->isEmpty()) {
            $fallback = User::where('role', 'kasir')->first()
                     ?? User::where('role', 'owner')->first();
            if ($fallback) $kasirs = collect([$fallback]);
        }

        if ($kasirs->isEmpty()) {
            $this->command->warn('DummyOrderSeeder: no user found, skipping.');
            return;
        }

        $menus = Menu::with('variants')->get();
        if ($menus->isEmpty()) {
            $this->command->warn('DummyOrderSeeder: no menus found, skipping.');
            return;
        }

        // Six orders spread across today's lunch + dinner — varied payment
        // methods and customer names so the Riwayat page looks realistic.
        $samples = [
            ['customer' => 'Andi',     'hour' => 11, 'minute' => 15, 'payment' => 'cash',     'cash' => 100000, 'picks' => ['Ayam Bakar:Paha:1', 'Es Teh Manis:_:2']],
            ['customer' => 'Sari',     'hour' => 12, 'minute' =>  5, 'payment' => 'qris',     'cash' => null,   'picks' => ['Ayam Goreng:Dada:1', 'Nasi Putih:_:1', 'Es Jeruk:_:1']],
            ['customer' => 'Budi',     'hour' => 12, 'minute' => 42, 'payment' => 'cash',     'cash' => 50000,  'picks' => ['Nasi Goreng:_:1', 'Kopi:Es:1']],
            ['customer' => 'Citra',    'hour' => 13, 'minute' => 20, 'payment' => 'qris',     'cash' => null,   'picks' => ['Ayam Bakar:Sayap:2', 'Es Teh Manis:_:2', 'Kentang Goreng:_:1']],
            ['customer' => 'Dewi',     'hour' => 18, 'minute' => 30, 'payment' => 'transfer', 'cash' => null,   'picks' => ['Ayam Bakar:Dada:1', 'Nasi Putih:_:1', 'Kopi:Panas:1']],
            ['customer' => null,       'hour' => 19, 'minute' => 10, 'payment' => 'cash',     'cash' => 80000,  'picks' => ['Nasi Goreng:_:2', 'Es Teh Manis:_:2']],
        ];

        $created = 0;
        foreach ($kasirs as $kasir) {
            foreach ($samples as $s) {
                $items = $this->resolveItems($menus, $s['picks']);
                if (empty($items)) continue;

                $total = array_sum(array_column($items, 'subtotal'));
                $when  = now()->setTime($s['hour'], $s['minute'], 0);

                $order = Order::create([
                    'user_id'        => $kasir->id,
                    'branch_id'      => $kasir->branch_id,
                    'customer_name'  => $s['customer'],
                    'total_price'    => $total,
                    'payment_method' => $s['payment'],
                    'cash_received'  => $s['cash'],
                    'change_amount'  => $s['cash'] ? max($s['cash'] - $total, 0) : null,
                    'status'         => 'completed',
                    'created_at'     => $when,
                    'updated_at'     => $when,
                ]);

                foreach ($items as $item) {
                    OrderItem::create([
                        'order_id'        => $order->id,
                        'menu_id'         => $item['menu_id'],
                        'menu_variant_id' => $item['menu_variant_id'],
                        'variant_name'    => $item['variant_name'],
                        'quantity'        => $item['quantity'],
                        'subtotal'        => $item['subtotal'],
                    ]);
                }
                $created++;
            }
        }

        $this->command->info("DummyOrderSeeder: {$created} dummy orders created across {$kasirs->count()} cabang (status=completed).");
    }

    /** "Ayam Bakar:Paha:2" => array of resolved item rows. */
    private function resolveItems($menus, array $picks): array
    {
        $out = [];
        foreach ($picks as $pick) {
            [$menuName, $variantName, $qty] = explode(':', $pick);
            $menu = $menus->firstWhere('name', $menuName);
            if (! $menu) continue;

            $variant = null;
            if ($variantName !== '_') {
                $variant = $menu->variants->firstWhere('name', $variantName);
                if (! $variant) continue;
            }

            $price = $variant?->price ?? $menu->price ?? 0;
            $out[] = [
                'menu_id'         => $menu->id,
                'menu_variant_id' => $variant?->id,
                'variant_name'    => $variant?->name,
                'quantity'        => (int) $qty,
                'subtotal'        => (int) $price * (int) $qty,
            ];
        }
        return $out;
    }
}
