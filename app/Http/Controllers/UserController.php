<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    // No auth middleware — public access

    /**
     * Get all active users
     */
    public function getAllUsers(Request $request)
    {
        $perPage = $request->input('per_page', 10);

        $users = User::where('is_archived', 0)->paginate($perPage);

        if ($users->isEmpty()) {
            return response()->json([
                'isSuccess' => false,
                'message'   => 'No active users found.',
            ], 404);
        }

        $users->getCollection()->transform(function ($user) {
            $user->profile_picture = $user->profile_picture
                ? asset($user->profile_picture)
                : null;
            return $user;
        });

        return response()->json([
            'isSuccess'  => true,
            'users'      => $users->items(),
            'pagination' => [
                'current_page' => $users->currentPage(),
                'per_page'     => $users->perPage(),
                'total'        => $users->total(),
                'last_page'    => $users->lastPage(),
            ],
        ]);
    }

    /**
     * Get single user by ID
     */
    public function getUserById($id)
    {
        $user = User::where('id', $id)
            ->where('is_archived', 0)
            ->first();

        if (!$user) {
            return response()->json([
                'isSuccess' => false,
                'message'   => 'User not found or archived.',
            ], 404);
        }

        $user->profile_picture = $user->profile_picture
            ? asset($user->profile_picture)
            : null;

        return response()->json([
            'isSuccess' => true,
            'user'      => $user
        ]);
    }

    /**
     * Register new user
     */
    public function createUser(Request $request)
    {
        try {
            $validated = $request->validate([
                'first_name' => 'required|string|max:150',
                'last_name'  => 'required|string|max:150',
                'username'   => 'required|string|unique:users,username',
                'email'      => 'required|email|unique:users,email',
                'password'   => 'required|string|min:6',
                'profile_picture' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
                'role_id'    => 'required|integer|exists:roles,id',
                'status'     => 'nullable|in:Active,Inactive,Suspended',
            ]);

            $validated['password'] = Hash::make($validated['password']);
            $validated['is_archived'] = 0;

            // Handle profile picture upload
            if ($request->hasFile('profile_picture')) {
                $validated['profile_picture'] = $this->saveFileToPublic($request, 'profile_picture', 'profile');
            }

            $user = User::create($validated);

            return response()->json([
                'isSuccess' => true,
                'message'   => 'User registered successfully.',
                'user'      => $user,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'isSuccess' => false,
                'message'   => 'Failed to register user.',
                'error'     => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update existing user
     */
    public function updateUser(Request $request, $id)
    {
        $user = User::where('id', $id)->where('is_archived', 0)->first();

        if (!$user) {
            return response()->json([
                'isSuccess' => false,
                'message'   => 'User not found or archived.',
            ], 404);
        }

        try {
            $validated = $request->validate([
                'first_name' => 'sometimes|string|max:150',
                'last_name'  => 'sometimes|string|max:150',
                'username'   => 'sometimes|string|unique:users,username,' . $id,
                'email'      => 'sometimes|email|unique:users,email,' . $id,
                'password'   => 'nullable|string|min:6',
                'profile_picture' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
                'role'       => 'nullable|in:Admin,HR,Manager,Employee',
                'status'     => 'nullable|in:Active,Inactive,Suspended',
            ]);

            if (!empty($validated['password'])) {
                $validated['password'] = Hash::make($validated['password']);
            } else {
                unset($validated['password']);
            }

            // Handle profile picture upload
            if ($request->hasFile('profile_picture')) {
                if ($user->profile_picture && file_exists(public_path($user->profile_picture))) {
                    unlink(public_path($user->profile_picture));
                }
                $validated['profile_picture'] = $this->saveFileToPublic($request, 'profile_picture', 'profile');
            }

            $user->update($validated);

            return response()->json([
                'isSuccess' => true,
                'message'   => 'User updated successfully.',
                'user'      => $user,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'isSuccess' => false,
                'message'   => 'Failed to update user.',
                'error'     => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Archive user (soft delete)
     */
    public function archiveUser($id)
    {
        $user = User::find($id);

        if (!$user || $user->is_archived) {
            return response()->json([
                'isSuccess' => false,
                'message'   => 'User not found or already archived.',
            ], 404);
        }

        $user->update(['is_archived' => 1]);

        return response()->json([
            'isSuccess' => true,
            'message'   => 'User archived successfully.',
        ]);
    }

    // ==========================
    // 🔧 File Upload Helper
    // ==========================
    private function saveFileToPublic(Request $request, $field, $prefix)
    {
        if ($request->hasFile($field)) {
            $file = $request->file($field);

            $directory = public_path('hris_files');
            if (!file_exists($directory)) {
                mkdir($directory, 0755, true);
            }

            $filename = $prefix . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $file->move($directory, $filename);

            return 'hris_files/' . $filename;
        }

        return null;
    }
}
