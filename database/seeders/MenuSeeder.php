<?php

namespace Database\Seeders;

use App\Models\Menu;
use App\Models\MenuVariant;
use Illuminate\Database\Seeder;

class MenuSeeder extends Seeder {
    public function run(): void {
        // Menu dengan varian ( price null di menu, price di varian )
        $ayamBakar = Menu::create( [ 'category_id' => 1, 'name' => 'Ayam Bakar', 'price' => null ] );
        MenuVariant::create( [ 'menu_id' => $ayamBakar->id, 'name' => 'Paha', 'price' => 25000 ] );
        MenuVariant::create( [ 'menu_id' => $ayamBakar->id, 'name' => 'Dada', 'price' => 27000 ] );
        MenuVariant::create( [ 'menu_id' => $ayamBakar->id, 'name' => 'Sayap', 'price' => 20000 ] );

        $ayamGoreng = Menu::create( [ 'category_id' => 1, 'name' => 'Ayam Goreng', 'price' => null ] );
        MenuVariant::create( [ 'menu_id' => $ayamGoreng->id, 'name' => 'Paha', 'price' => 25000 ] );
        MenuVariant::create( [ 'menu_id' => $ayamGoreng->id, 'name' => 'Dada', 'price' => 27000 ] );

        // Menu tanpa varian ( price langsung di menu )
        Menu::create( [ 'category_id' => 1, 'name' => 'Nasi Goreng', 'price' => 20000 ] );
        Menu::create( [ 'category_id' => 1, 'name' => 'Nasi Putih', 'price' => 5000 ] );

        // Minuman
        Menu::create( [ 'category_id' => 2, 'name' => 'Es Teh Manis', 'price' => 5000 ] );
        Menu::create( [ 'category_id' => 2, 'name' => 'Es Jeruk', 'price' => 7000 ] );

        $kopi = Menu::create( [ 'category_id' => 2, 'name' => 'Kopi', 'price' => null ] );
        MenuVariant::create( [ 'menu_id' => $kopi->id, 'name' => 'Panas', 'price' => 5000 ] );
        MenuVariant::create( [ 'menu_id' => $kopi->id, 'name' => 'Es', 'price' => 7000 ] );

        // Snack
        Menu::create( [ 'category_id' => 3, 'name' => 'Kentang Goreng', 'price' => 15000 ] );
    }
}
