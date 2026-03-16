<?php

namespace App\Http\Controllers;

use App\Models\Department;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    /**
     * Get all active departments
     */
    public function getAllDepartments(Request $request)
    {
        $perPage = $request->input('per_page', 10);

        $departments = Department::with([
            'head:id,first_name,last_name,email,role_id',
            'head.role:id,role_name'
        ])
            ->withCount('employees') //  adds total employees count
            ->where('is_archived', 0)
            ->paginate($perPage);

        if ($departments->isEmpty()) {
            return response()->json([
                'isSuccess' => false,
                'message'   => 'No active departments found.',
            ], 404);
        }

        // Map departments with head details and employee count
        $formattedDepartments = $departments->map(function ($dept) {
            return [
                'id'              => $dept->id,
                'department_name' => $dept->department_name,
                'description'     => $dept->description,
                'head' => $dept->head ? [
                    'id'         => $dept->head->id,
                    'first_name' => $dept->head->first_name,
                    'last_name'  => $dept->head->last_name,
                    'email'      => $dept->head->email,
                    'role_name'  => $dept->head->role ? $dept->head->role->role_name : null,
                ] : null,
                'total_employees' => $dept->employees_count, // total employees
                'is_active'  => $dept->is_active,
                'is_archived' => $dept->is_archived,
                'created_at' => $dept->created_at,
                'updated_at' => $dept->updated_at,
            ];
        });

        return response()->json([
            'isSuccess'   => true,
            'departments' => $formattedDepartments,
            'pagination'  => [
                'current_page' => $departments->currentPage(),
                'per_page'     => $departments->perPage(),
                'total'        => $departments->total(),
                'last_page'    => $departments->lastPage(),
            ],
        ]);
    }


    /**
     * Get a single department by ID
     */
    public function getDepartmentById($id)
    {
        $department = Department::with([
            'head:id,first_name,last_name,email,role_id',
            'head.role:id,role_name'
        ])
            ->where('id', $id)
            ->where('is_archived', 0)
            ->first();

        if (!$department) {
            return response()->json([
                'isSuccess' => false,
                'message'   => 'Department not found or archived.',
            ], 404);
        }

        return response()->json([
            'isSuccess'  => true,
            'department' => $department,
        ]);
    }

    /**
     * Create new department
     */
    public function createDepartment(Request $request)
    {
        try {
            $validated = $request->validate([
                'department_name' => 'required|string|max:150|unique:departments,department_name',
                'description'     => 'nullable|string',
                'head_id'         => 'nullable|integer|exists:users,id',
                'is_active'       => 'nullable|boolean',
            ]);

            $validated['is_archived'] = 0;

            $department = Department::create($validated);

            return response()->json([
                'isSuccess'  => true,
                'message'    => 'Department created successfully.',
                'department' => $department,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'isSuccess' => false,
                'message'   => 'Failed to create department.',
                'error'     => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update department
     */
    public function updateDepartment(Request $request, $id)
    {
        $department = Department::where('id', $id)->where('is_archived', 0)->first();

        if (!$department) {
            return response()->json([
                'isSuccess' => false,
                'message'   => 'Department not found or archived.',
            ], 404);
        }

        try {
            $validated = $request->validate([
                'department_name' => 'sometimes|string|max:150|unique:departments,department_name,' . $id,
                'description'     => 'sometimes|nullable|string',
                'head_id'         => 'sometimes|nullable|integer|exists:users,id',
                'is_active'       => 'sometimes|boolean',
            ]);

            $department->update($validated);

            return response()->json([
                'isSuccess'  => true,
                'message'    => 'Department updated successfully.',
                'department' => $department,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'isSuccess' => false,
                'message'   => 'Failed to update department.',
                'error'     => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Archive department (soft delete)
     */
    public function archiveDepartment($id)
    {
        $department = Department::find($id);

        if (!$department || $department->is_archived) {
            return response()->json([
                'isSuccess' => false,
                'message'   => 'Department not found or already archived.',
            ], 404);
        }

        $department->update(['is_archived' => 1]);

        return response()->json([
            'isSuccess' => true,
            'message'   => 'Department archived successfully.',
        ]);
    }
}
