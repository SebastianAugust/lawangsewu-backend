<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
 {
    protected $fillable = [ 'user_id', 'action', 'target_type', 'target_id', 'details', 'ip_address' ];

    protected $casts = [
        'details' => 'array',
    ];

    public function user()
 {
        return $this->belongsTo( User::class );
    }

    // Helper untuk bikin log gampang
    public static function record( $userId, $action, $targetType = null, $targetId = null, $details = null )
 {
        return self::create( [
            'user_id' => $userId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'details' => $details,
            'ip_address' => request()->ip(),
        ] );
    }
}
