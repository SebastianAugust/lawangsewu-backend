<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $fillable = ['order_id', 'menu_id', 'menu_variant_id', 'variant_name', 'quantity', 'subtotal'];

    public function menu()
    {
        return $this->belongsTo(Menu::class);
    }

    public function variant()
    {
        return $this->belongsTo(MenuVariant::class, 'menu_variant_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
