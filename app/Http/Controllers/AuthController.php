<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required',
        ]);

        $user = User::where('username', $request->username)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'username' => ['Username atau password salah.'],
            ]);
        }

        // Block only accounts explicitly deactivated. If the is_active column
        // hasn't been migrated yet (value null), login still works — this keeps
        // a code-before-migration deploy from locking everyone out.
        if ($user->is_active === false) {
            throw ValidationException::withMessages([
                'username' => ['Akun nonaktif. Hubungi owner.'],
            ]);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        $user->load('branch');

        AuditLog::record($user->id, 'login', 'User', $user->id, [
            'username' => $user->username,
            'role' => $user->role,
        ]);

        return response()->json([
            'user' => $user,
            'token' => $token,
            'branch_id' => $user->branch_id,
            'branch_name' => $user->branch?->name,
        ]);
    }

    public function logout(Request $request)
    {
        AuditLog::record($request->user()->id, 'logout', 'User', $request->user()->id);

        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out']);
    }

    public function me(Request $request)
    {
        return response()->json($request->user()->load('branch'));
    }
}
