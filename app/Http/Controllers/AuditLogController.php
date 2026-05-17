<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
 {
    public function index( Request $request )
 {
        $query = AuditLog::with( 'user' )->orderBy( 'created_at', 'desc' );

        if ( $request->has( 'date' ) ) {
            $query->whereDate( 'created_at', $request->date );
        }

        if ( $request->has( 'action' ) ) {
            $query->where( 'action', $request->action );
        }

        if ( $request->has( 'user_id' ) ) {
            $query->where( 'user_id', $request->user_id );
        }

        $logs = $query->paginate( 50 );

        return response()->json( $logs );
    }
}
