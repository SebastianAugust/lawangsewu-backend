<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Branch extends Model {
    protected $fillable = [
        'name',
        'address',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function users() {
        return $this->hasMany( User::class );
    }

    public function orders() {
        return $this->hasMany( Order::class );
    }
}
