<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use Illuminate\Http\Request;

class BranchController extends Controller {
    public function index() {
        return response()->json( Branch::orderBy( 'id' )->get() );
    }

    public function store( Request $request ) {
        $this->ensureOwner( $request );

        $data = $request->validate( [
            'name' => 'required|string|max:255',
            'address' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
        ] );

        $branch = Branch::create( $data );

        return response()->json( $branch, 201 );
    }

    public function update( Request $request, Branch $branch ) {
        $this->ensureOwner( $request );

        $data = $request->validate( [
            'name' => 'required|string|max:255',
            'address' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
        ] );

        $branch->update( $data );

        return response()->json( $branch );
    }

    public function destroy( Request $request, Branch $branch ) {
        $this->ensureOwner( $request );

        // branch_id is nullOnDelete on users & orders, so deleting a cabang
        // leaves historical data intact (it just becomes unassigned).
        $branch->delete();

        return response()->json( [ 'message' => 'Cabang dihapus' ] );
    }

    private function ensureOwner( Request $request ): void {
        abort_if( $request->user()->role !== 'owner', 403, 'Hanya owner yang dapat mengelola cabang.' );
    }
}
