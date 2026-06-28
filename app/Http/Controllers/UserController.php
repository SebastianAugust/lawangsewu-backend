<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Branch;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller {
    public function index( Request $request ) {
        $this->ensureOwner( $request );

        // Owner only manages kasir accounts here; owner accounts are off-limits.
        // Each kasir is bound 1:1 to its own cabang.
        $users = User::where( 'role', 'kasir' )
            ->with( 'branch' )
            ->orderBy( 'name' )
            ->get();

        return response()->json( $users );
    }

    public function store( Request $request ) {
        $this->ensureOwner( $request );

        // One cabang = one akun: a new cabang and its kasir account are created
        // together, so a branch can never end up with two accounts.
        $data = $request->validate( [
            'branch_name' => 'required|string|max:255',
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:6',
        ] );

        $user = DB::transaction( function () use ( $data ) {
            $branch = Branch::create( [
                'name' => $data['branch_name'],
                'is_active' => true,
            ] );

            return User::create( [
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make( $data['password'] ),
                'role' => 'kasir',
                'branch_id' => $branch->id,
                'is_active' => true,
            ] );
        } );

        AuditLog::record( $request->user()->id, 'create_user', 'User', $user->id, [
            'name' => $user->name,
            'email' => $user->email,
            'branch_id' => $user->branch_id,
        ] );

        $user->load( 'branch' );

        return response()->json( $user, 201 );
    }

    public function update( Request $request, User $user ) {
        $this->ensureOwner( $request );

        // Guard: only kasir accounts are editable through this endpoint.
        abort_if( $user->role !== 'kasir', 403, 'Hanya akun kasir yang dapat dikelola.' );

        $data = $request->validate( [
            'branch_name' => 'sometimes|required|string|max:255',
            'name' => 'sometimes|required|string|max:255',
            'email' => [ 'sometimes', 'required', 'email', 'max:255', Rule::unique( 'users', 'email' )->ignore( $user->id ) ],
            'password' => 'nullable|string|min:6',
            'is_active' => 'sometimes|boolean',
        ] );

        // Empty/absent password means "keep the existing one".
        if ( ! empty( $data['password'] ) ) {
            $data['password'] = Hash::make( $data['password'] );
        } else {
            unset( $data['password'] );
        }

        DB::transaction( function () use ( $user, $data ) {
            // Rename the cabang and/or keep its active state in sync with the akun.
            if ( $user->branch ) {
                $branchData = [];
                if ( array_key_exists( 'branch_name', $data ) ) {
                    $branchData['name'] = $data['branch_name'];
                }
                if ( array_key_exists( 'is_active', $data ) ) {
                    $branchData['is_active'] = $data['is_active'];
                }
                if ( $branchData ) {
                    $user->branch->update( $branchData );
                }
            }

            unset( $data['branch_name'] );
            $user->update( $data );
        } );

        AuditLog::record( $request->user()->id, 'update_user', 'User', $user->id, [
            'name' => $user->name,
            'email' => $user->email,
            'branch_id' => $user->branch_id,
            'is_active' => $user->is_active,
        ] );

        $user->load( 'branch' );

        return response()->json( $user );
    }

    private function ensureOwner( Request $request ): void {
        abort_if( $request->user()->role !== 'owner', 403, 'Hanya owner yang dapat mengelola pengguna.' );
    }
}
