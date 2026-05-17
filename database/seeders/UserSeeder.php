<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder {
    public function run(): void {
        User::create( [
            'name' => 'Owner',
            'email' => 'owner@lawangsewu.com',
            'password' => Hash::make( 'password123' ),
            'role' => 'owner',
        ] );

        User::create( [
            'name' => 'Kasir',
            'email' => 'kasir@lawangsewu.com',
            'password' => Hash::make( 'password123' ),
            'role' => 'kasir',
        ] );
    }
}
