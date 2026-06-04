<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model {
    protected $fillable = [
        'user_id', 'branch_id', 'customer_name', 'total_price', 'payment_method',
        'cash_received', 'change_amount', 'status', 'is_test',
        'void_reason', 'voided_by', 'voided_at',
    ];

    protected $casts = [
        'voided_at' => 'datetime',
        'is_test'   => 'boolean',
    ];

    public function items() {
        return $this->hasMany( OrderItem::class );
    }

    public function user() {
        return $this->belongsTo( User::class );
    }

    public function branch() {
        return $this->belongsTo( Branch::class );
    }

    public function voidedByUser() {
        return $this->belongsTo( User::class, 'voided_by' );
    }
}
