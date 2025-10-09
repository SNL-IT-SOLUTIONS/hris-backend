<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\Employee;

class AuthController extends Controller
{
    /**
     * User Login (email or username)
     */


    public function login(Request $request)
    {
        $credentials = $request->validate([
            'login'    => 'required|string', // email or username
            'password' => 'required|string',
        ]);

        $loginInput = $credentials['login'];
        $password = $credentials['password'];

        $user = null;
        $role = null;

        // Check employees first
        $employee = Employee::where('email', $loginInput)->first();
        if ($employee && Hash::check($password, $employee->password)) {
            $user = $employee;
            $role = 'employee';
        }

        // If not found, then check users
        if (!$user) {
            $fieldType = filter_var($loginInput, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
            $userModel = User::where($fieldType, $loginInput)->first();
            if ($userModel && Hash::check($password, $userModel->password)) {
                $user = $userModel;
                $role = 'user';
            }
        }


        // 3️⃣ If still not found, invalid login
        if (!$user) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Invalid login credentials.',
            ], 401);
        }

        // 4️⃣ Optional: check active status
        if (isset($user->is_active) && !$user->is_active) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Your account is inactive.',
            ], 403);
        }

        // 5️⃣ Generate token
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'isSuccess' => true,
            'message' => 'Login successful.',
            'role' => $role,
            'user' => [
                'id'          => $user->id,
                'first_name'  => $user->first_name,
                'last_name'   => $user->last_name,
                'email'       => $user->email,
                'username'    => $role === 'user' ? $user->username : null,
                'phone'       => $role === 'employee' ? $user->phone : null,
                'department_id' => $role === 'employee' ? $user->department_id : null,
                'position_id'   => $role === 'employee' ? $user->position_id : null,
            ],
            'token' => $token,
        ]);
    }


    /**
     * User Logout
     */
    public function logout(Request $request)
    {
        $user = $request->user();

        if ($user) {
            // Revoke current token
            $user->currentAccessToken()->delete();

            return response()->json([
                'isSuccess' => true,
                'message'   => 'Logged out successfully.',
            ]);
        }

        return response()->json([
            'isSuccess' => false,
            'message'   => 'User not authenticated.',
        ], 401);
    }

    /**
     * Get Authenticated User Info
     */
    public function me(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'isSuccess' => false,
                'message'   => 'No authenticated user found.',
            ], 401);
        }

        return response()->json([
            'isSuccess' => true,
            'user'      => $user,
        ]);
    }
}
