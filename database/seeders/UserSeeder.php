<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder {
    public function run(): void {
        // Owner is not tied to a branch — branch_id null means "lihat semua cabang".
        User::create( [
            'name' => 'Owner',
            'username' => 'owner',
            'email' => 'owner@lawangsewu.com',
            'password' => Hash::make( 'password123' ),
            'role' => 'owner',
            'branch_id' => null,
        ] );

        // Each kasir is bound to one branch. Assumes BranchSeeder ran first
        // (branch 1 = Pusat, branch 2 = Cabang 2).
        User::create( [
            'name' => 'Kasir',
            'username' => 'kasir',
            'email' => 'kasir@lawangsewu.com',
            'password' => Hash::make( 'password123' ),
            'role' => 'kasir',
            'branch_id' => 1,
        ] );

        User::create( [
            'name' => 'Kasir Cabang 2',
            'username' => 'kasir2',
            'email' => 'kasir2@lawangsewu.com',
            'password' => Hash::make( 'password123' ),
            'role' => 'kasir',
            'branch_id' => 2,
        ] );
    }
}
