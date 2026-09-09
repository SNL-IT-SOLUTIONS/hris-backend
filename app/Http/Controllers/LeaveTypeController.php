<?php

namespace App\Http\Controllers;

use App\Models\LeaveType;
use App\Models\Employee;
use App\Models\EmployeeLeaveType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LeaveTypeController extends Controller
{
    // ================================
    // Get All Active Leave Types
    // ================================

    public function getAllLeaveTypes(Request $request)
    {
        $perPage = $request->input('per_page', 10);

        $leaveTypes = LeaveType::where('is_archived', 0)
            ->paginate($perPage);

        if ($leaveTypes->isEmpty()) {
            return response()->json([
                'isSuccess' => false,
                'message'   => 'No active leave types found.',
            ], 404);
        }

        return response()->json([
            'isSuccess'   => true,
            'leave_types' => $leaveTypes->items(),
            'pagination'  => [
                'current_page' => $leaveTypes->currentPage(),
                'per_page'     => $leaveTypes->perPage(),
                'total'        => $leaveTypes->total(),
                'last_page'    => $leaveTypes->lastPage(),
            ],
        ]);
    }

    // ================================
    // Get Single Leave Type by ID
    // ================================

    public function getLeaveTypeById($id)
    {
        $leaveType = LeaveType::where('id', $id)
            ->where('is_archived', 0)
            ->first();

        if (!$leaveType) {
            return response()->json([
                'isSuccess' => false,
                'message'   => 'Leave type not found or archived.',
            ], 404);
        }

        return response()->json([
            'isSuccess'  => true,
            'leave_type' => $leaveType,
        ]);
    }

    // ================================
    // Create Leave Type
    // ================================

    public function createLeaveType(Request $request)
    {
        try {

            $validated = $request->validate([
                'leave_name' => 'required|string|max:150|unique:leave_types,leave_name',
                'description' => 'nullable|string',
                'max_days' => 'required|integer|min:1',
                'is_paid' => 'required|boolean',
                'is_active' => 'nullable|boolean',

                // Employee assignments
                'employees' => 'nullable|array',
                'employees.*.employee_id' => 'required|integer|exists:employees,id',
                'employees.*.allocated_days' => 'required|numeric|min:0',
            ]);

            DB::beginTransaction();

            // ================================
            // Create Leave Type
            // ================================

            $leaveType = LeaveType::create([
                'leave_name' => $validated['leave_name'],
                'description' => $validated['description'] ?? null,
                'max_days' => $validated['max_days'],
                'is_paid' => $validated['is_paid'],
                'is_active' => $validated['is_active'] ?? 1,
                'is_archived' => 0,
            ]);

            $assignedEmployees = [];

            // ================================
            // Assign Leave Type to Employees
            // ================================

            if (!empty($validated['employees'])) {

                foreach ($validated['employees'] as $employeeData) {

                    // Check employee
                    $employee = Employee::where(
                        'id',
                        $employeeData['employee_id']
                    )
                        ->where('is_archived', 0)
                        ->where('is_active', 1)
                        ->first();

                    if (!$employee) {
                        DB::rollBack();

                        return response()->json([
                            'isSuccess' => false,
                            'message' => 'One or more employees are inactive, archived, or do not exist.',
                        ], 404);
                    }

                    // Create assignment
                    $employeeLeaveType = EmployeeLeaveType::create([
                        'employee_id' => $employee->id,
                        'leave_type_id' => $leaveType->id,
                        'allocated_days' => $employeeData['allocated_days'],
                        'used_days' => 0,
                        'remaining_days' => $employeeData['allocated_days'],
                        'is_active' => 1,
                        'is_archived' => 0,
                    ]);

                    $employeeLeaveType->load([
                        'employee',
                        'leaveType',
                    ]);

                    $assignedEmployees[] = $employeeLeaveType;
                }
            }

            DB::commit();

            return response()->json([
                'isSuccess' => true,
                'message' => 'Leave type created successfully.',
                'leave_type' => $leaveType,
                'assigned_employees' => $assignedEmployees,
            ], 201);
        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to create leave type.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ================================
    // Update Leave Type
    // ================================

    public function updateLeaveType(Request $request, $id)
    {
        $leaveType = LeaveType::where('id', $id)
            ->where('is_archived', 0)
            ->first();

        if (!$leaveType) {
            return response()->json([
                'isSuccess' => false,
                'message'   => 'Leave type not found or archived.',
            ], 404);
        }

        try {

            $validated = $request->validate([
                'leave_name' => 'sometimes|string|max:150|unique:leave_types,leave_name,' . $id,
                'description' => 'nullable|string',
                'max_days' => 'sometimes|integer|min:1',
                'is_paid' => 'sometimes|boolean',
                'is_active' => 'sometimes|boolean',
            ]);

            $leaveType->update($validated);

            return response()->json([
                'isSuccess'  => true,
                'message'    => 'Leave type updated successfully.',
                'leave_type' => $leaveType,
            ]);
        } catch (\Exception $e) {

            return response()->json([
                'isSuccess' => false,
                'message'   => 'Failed to update leave type.',
                'error'     => $e->getMessage(),
            ], 500);
        }
    }

    // ================================
    // Archive Leave Type (Soft Delete)
    // ================================

    public function archiveLeaveType($id)
    {
        $leaveType = LeaveType::find($id);

        if (!$leaveType || $leaveType->is_archived) {
            return response()->json([
                'isSuccess' => false,
                'message'   => 'Leave type not found or already archived.',
            ], 404);
        }

        $leaveType->update([
            'is_archived' => 1
        ]);

        return response()->json([
            'isSuccess' => true,
            'message'   => 'Leave type archived successfully.',
        ]);
    }

    // ================================
    // Assign Leave Type to Employee
    // ================================

    // public function assignLeaveTypeToEmployee(Request $request)
    // {
    //     try {

    //         $validated = $request->validate([
    //             'employee_id' => 'required|integer|exists:employees,id',
    //             'leave_type_id' => 'required|integer|exists:leave_types,id',
    //             'allocated_days' => 'required|numeric|min:0',
    //         ]);

    //         // Check employee
    //         $employee = Employee::where('id', $validated['employee_id'])
    //             ->where('is_archived', 0)
    //             ->where('is_active', 1)
    //             ->first();

    //         if (!$employee) {
    //             return response()->json([
    //                 'isSuccess' => false,
    //                 'message' => 'Employee not found, inactive, or archived.',
    //             ], 404);
    //         }

    //         // Check leave type
    //         $leaveType = LeaveType::where('id', $validated['leave_type_id'])
    //             ->where('is_archived', 0)
    //             ->where('is_active', 1)
    //             ->first();

    //         if (!$leaveType) {
    //             return response()->json([
    //                 'isSuccess' => false,
    //                 'message' => 'Leave type not found, inactive, or archived.',
    //             ], 404);
    //         }

    //         // Check if already assigned
    //         $existingAssignment = EmployeeLeaveType::where(
    //             'employee_id',
    //             $validated['employee_id']
    //         )
    //             ->where(
    //                 'leave_type_id',
    //                 $validated['leave_type_id']
    //             )
    //             ->where('is_archived', 0)
    //             ->first();

    //         if ($existingAssignment) {
    //             return response()->json([
    //                 'isSuccess' => false,
    //                 'message' => 'This leave type is already assigned to this employee.',
    //             ], 409);
    //         }

    //         // Create assignment
    //         $employeeLeaveType = EmployeeLeaveType::create([
    //             'employee_id' => $validated['employee_id'],
    //             'leave_type_id' => $validated['leave_type_id'],
    //             'allocated_days' => $validated['allocated_days'],
    //             'used_days' => 0,
    //             'remaining_days' => $validated['allocated_days'],
    //             'is_active' => 1,
    //             'is_archived' => 0,
    //         ]);

    //         $employeeLeaveType->load([
    //             'employee',
    //             'leaveType'
    //         ]);

    //         return response()->json([
    //             'isSuccess' => true,
    //             'message' => 'Leave type assigned to employee successfully.',
    //             'employee_leave_type' => $employeeLeaveType,
    //         ], 201);
    //     } catch (\Exception $e) {

    //         return response()->json([
    //             'isSuccess' => false,
    //             'message' => 'Failed to assign leave type to employee.',
    //             'error' => $e->getMessage(),
    //         ], 500);
    //     }
    // }
}
