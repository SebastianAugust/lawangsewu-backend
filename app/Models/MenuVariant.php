<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MenuVariant extends Model {
    protected $fillable = [ 'menu_id', 'name', 'price', 'is_available' ];

    public function menu() {
        return $this->belongsTo( Menu::class );
    }
}
