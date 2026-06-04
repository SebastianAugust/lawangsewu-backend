<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder {
    public function run(): void {
        Branch::create( [
            'name' => 'Lawang Sewu Pusat',
            'address' => 'Jl. Pemuda No. 160, Semarang',
            'is_active' => true,
        ] );

        Branch::create( [
            'name' => 'Lawang Sewu Cabang 2',
            'address' => 'Jl. Pandanaran No. 12, Semarang',
            'is_active' => true,
        ] );
    }
}
