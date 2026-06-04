<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class OrderController extends Controller {
    public function store( Request $request ) {
        $request->validate( [
            'customer_name' => 'nullable|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.menu_id' => 'required|exists:menus,id',
            'items.*.menu_variant_id' => 'nullable|exists:menu_variants,id',
            'items.*.variant_name' => 'nullable|string',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.subtotal' => 'required|integer|min:0',
            'payment_method' => 'required|in:cash,qris,transfer',
            'cash_received' => 'required_if:payment_method,cash|nullable|integer|min:0',
            'is_test' => 'nullable|boolean',
        ] );

        $totalPrice = collect( $request->items )->sum( 'subtotal' );

        $changeAmount = null;
        if ( $request->payment_method === 'cash' && $request->cash_received ) {
            $changeAmount = $request->cash_received - $totalPrice;
        }

        $order = Order::create( [
            'user_id' => $request->user()->id,
            // Branch is taken from the logged-in user, never from client input,
            // so a kasir's orders always belong to their own cabang. Owner
            // (branch_id null) creating an order leaves it unassigned.
            'branch_id' => $request->user()->branch_id,
            'customer_name' => $request->customer_name,
            'total_price' => $totalPrice,
            'payment_method' => $request->payment_method,
            'cash_received' => $request->cash_received,
            'change_amount' => $changeAmount,
            'is_test' => (bool) $request->input('is_test', false),
        ] );

        foreach ( $request->items as $item ) {
            OrderItem::create( [
                'order_id' => $order->id,
                'menu_id' => $item[ 'menu_id' ],
                'menu_variant_id' => $item[ 'menu_variant_id' ] ?? null,
                'variant_name' => $item[ 'variant_name' ] ?? null,
                'quantity' => $item[ 'quantity' ],
                'subtotal' => $item[ 'subtotal' ],
            ] );
        }

        $order->load( 'items.menu', 'items.variant', 'user' );

        AuditLog::record( $request->user()->id, 'create_order', 'Order', $order->id, [
            'customer_name' => $request->customer_name,
            'total_price' => $totalPrice,
            'payment_method' => $request->payment_method,
            'items_count' => count( $request->items ),
        ] );

        return response()->json( $order, 201 );
    }

    public function index( Request $request ) {
        $query = Order::with( 'items.menu', 'user', 'branch' )->orderBy( 'created_at', 'desc' );

        if ( $request->has( 'date' ) ) {
            $query->whereDate( 'created_at', $request->date );
        }

        if ( $request->has( 'status' ) ) {
            $query->where( 'status', $request->status );
        }

        // A kasir only ever sees their own cabang. An owner sees everything,
        // but may optionally narrow to one cabang via ?branch_id=.
        $user = $request->user();
        if ( $user->role === 'kasir' && $user->branch_id ) {
            $query->where( 'branch_id', $user->branch_id );
        } elseif ( $user->role === 'owner' && $request->filled( 'branch_id' ) ) {
            $query->where( 'branch_id', $request->branch_id );
        }

        return response()->json( $query->get() );
    }

    public function show( Order $order ) {
        $order->load( 'items.menu', 'user', 'voidedByUser' );
        return response()->json( $order );
    }

    public function requestVoid( Request $request, Order $order ) {
        $request->validate( [
            'void_reason' => 'required|string|max:255',
        ] );

        if ( $order->status !== 'completed' ) {
            return response()->json( [ 'message' => 'Order ini tidak bisa di-void' ], 400 );
        }

        $order->update( [
            'status' => 'void_pending',
            'void_reason' => $request->void_reason,
        ] );

        AuditLog::record( $request->user()->id, 'void_request', 'Order', $order->id, [
            'reason' => $request->void_reason,
            'total_price' => $order->total_price,
        ] );

        return response()->json( [ 'message' => 'Permintaan void dikirim ke owner' ] );
    }

    public function approveVoid( Request $request, Order $order ) {
        if ( $order->status !== 'void_pending' ) {
            return response()->json( [ 'message' => 'Tidak ada permintaan void' ], 400 );
        }

        $order->update( [
            'status' => 'voided',
            'voided_by' => $request->user()->id,
            'voided_at' => now(),
        ] );

        AuditLog::record( $request->user()->id, 'void_approve', 'Order', $order->id, [
            'reason' => $order->void_reason,
            'total_price' => $order->total_price,
        ] );

        return response()->json( [ 'message' => 'Void disetujui' ] );
    }

    public function rejectVoid( Request $request, Order $order ) {
        if ( $order->status !== 'void_pending' ) {
            return response()->json( [ 'message' => 'Tidak ada permintaan void' ], 400 );
        }

        AuditLog::record( $request->user()->id, 'void_reject', 'Order', $order->id, [
            'reason' => $order->void_reason,
            'total_price' => $order->total_price,
        ] );

        $order->update( [
            'status' => 'completed',
            'void_reason' => null,
        ] );

        return response()->json( [ 'message' => 'Void ditolak' ] );
    }

    public function voidRequests() {
        $orders = Order::with( 'items.menu', 'user' )
        ->where( 'status', 'void_pending' )
        ->orderBy( 'created_at', 'desc' )
        ->get();

        return response()->json( $orders );
    }

    /**
     * Delete all test orders created by the current user. Used by the
     * guided tour to clean up dummy orders the user made during the
     * tutorial so they don't pollute real history.
     */
    public function cleanupTest( Request $request ) {
        $userId = $request->user()->id;

        $orderIds = Order::where( 'user_id', $userId )
            ->where( 'is_test', true )
            ->pluck( 'id' );

        if ( $orderIds->isEmpty() ) {
            return response()->json( [ 'deleted' => 0 ] );
        }

        OrderItem::whereIn( 'order_id', $orderIds )->delete();
        $deleted = Order::whereIn( 'id', $orderIds )->delete();

        return response()->json( [ 'deleted' => $deleted ] );
    }
}
