<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Menu extends Model {
    protected $fillable = [ 'category_id', 'name', 'price', 'is_available', 'image' ];

    public function category() {
        return $this->belongsTo( Category::class );
    }

    public function variants() {
        return $this->hasMany( MenuVariant::class );
    }
}
