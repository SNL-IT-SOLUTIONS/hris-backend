<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\CompanyInformation;
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

        //  Check employees first
        $employee = Employee::where('email', $loginInput)->first();
        if ($employee && Hash::check($password, $employee->password)) {
            $user = $employee;
            $role = 'employee';
        }

        //  If not found, check users
        if (!$user) {
            $fieldType = filter_var($loginInput, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
            $userModel = User::where($fieldType, $loginInput)->first();
            if ($userModel && Hash::check($password, $userModel->password)) {
                $user = $userModel;
                $role = 'user';
            }
        }

        // Invalid login
        if (!$user) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Invalid login credentials.',
            ], 401);
        }

        // Optional: check if account is active
        if (isset($user->is_active) && !$user->is_active) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Your account is inactive.',
            ], 403);
        }

        // Generate token
        $token = $user->createToken('auth_token')->plainTextToken;

        // Fetch company info (assuming single record)
        $company = CompanyInformation::first();

        // Response
        return response()->json([
            'isSuccess' => true,
            'message' => 'Login successful.',
            'role' => $role,
            'user' => [
                'id'            => $user->id,
                'first_name'    => $user->first_name,
                'last_name'     => $user->last_name,
                'email'         => $user->email,
                'username'      => $role === 'user' ? $user->username : null,
                'phone'         => $role === 'employee' ? $user->phone : null,
                'department_id' => $role === 'employee' ? $user->department_id : null,
                'position_id'   => $role === 'employee' ? $user->position_id : null,
            ],
            'company_information' => $company ? [
                'id' => $company->id,
                'company_name' => $company->company_name,
                'company_logo' => $company->company_logo,
                'industry' => $company->industry,
                'founded_year' => $company->founded_year,
                'website' => $company->website,
                'company_mission' => $company->company_mission,
                'company_vision' => $company->company_vision,
                'registration_number' => $company->registration_number,
                'tax_id_ein' => $company->tax_id_ein,
                'primary_email' => $company->primary_email,
                'phone_number' => $company->phone_number,
                'street_address' => $company->street_address,
                'city' => $company->city,
                'state_province' => $company->state_province,
                'postal_code' => $company->postal_code,
                'country' => $company->country,
            ] : null,
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
