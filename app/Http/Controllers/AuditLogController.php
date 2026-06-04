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

        // Scope logs by the cabang of the user who performed each action.
        // A kasir is locked to their own cabang; an owner may optionally
        // narrow to one cabang via ?branch_id=.
        $user = $request->user();
        if ( $user->role === 'kasir' && $user->branch_id ) {
            $query->whereHas( 'user', fn ( $q ) => $q->where( 'branch_id', $user->branch_id ) );
        } elseif ( $user->role === 'owner' && $request->filled( 'branch_id' ) ) {
            $branchId = $request->branch_id;
            $query->whereHas( 'user', fn ( $q ) => $q->where( 'branch_id', $branchId ) );
        }

        $logs = $query->paginate( 50 );

        return response()->json( $logs );
    }
}
