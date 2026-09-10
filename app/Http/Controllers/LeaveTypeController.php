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
            ->with([
                'employeeLeaveTypes' => function ($query) {
                    $query->where('is_archived', 0)
                        ->where('is_active', 1)
                        ->with([
                            'employee'
                        ]);
                }
            ])
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

            // ========================================
            // CREATE LEAVE TYPE
            // ========================================

            $leaveType = LeaveType::create([
                'leave_name' => $validated['leave_name'],
                'description' => $validated['description'] ?? null,
                'max_days' => $validated['max_days'],
                'is_paid' => $validated['is_paid'],
                'is_active' => $validated['is_active'] ?? 1,
                'is_archived' => 0,
            ]);

            $assignedEmployees = [];

            // ========================================
            // ASSIGN LEAVE TYPE TO EMPLOYEES
            // ========================================

            if (isset($validated['employees']) && count($validated['employees']) > 0) {

                foreach ($validated['employees'] as $employeeData) {

                    // ----------------------------------------
                    // Check employee
                    // ----------------------------------------

                    $employee = Employee::where('id', $employeeData['employee_id'])
                        ->where('is_archived', 0)
                        ->where('is_active', 1)
                        ->first();

                    if (!$employee) {

                        DB::rollBack();

                        return response()->json([
                            'isSuccess' => false,
                            'message' => 'Employee is inactive, archived, or does not exist.',
                            'employee_id' => $employeeData['employee_id'],
                        ], 404);
                    }

                    // ----------------------------------------
                    // Check duplicate assignment
                    // ----------------------------------------

                    $existingAssignment = EmployeeLeaveType::where(
                        'employee_id',
                        $employee->id
                    )
                        ->where('leave_type_id', $leaveType->id)
                        ->where('is_archived', 0)
                        ->first();

                    if ($existingAssignment) {

                        DB::rollBack();

                        return response()->json([
                            'isSuccess' => false,
                            'message' => 'Employee is already assigned to this leave type.',
                            'employee_id' => $employee->id,
                            'leave_type_id' => $leaveType->id,
                        ], 409);
                    }

                    // ----------------------------------------
                    // Create employee leave assignment
                    // ----------------------------------------

                    $employeeLeaveType = EmployeeLeaveType::create([
                        'employee_id' => $employee->id,
                        'leave_type_id' => $leaveType->id,
                        'allocated_days' => $employeeData['allocated_days'],
                        'used_days' => 0,
                        'remaining_days' => $employeeData['allocated_days'],
                        'is_active' => 1,
                        'is_archived' => 0,
                    ]);

                    // ----------------------------------------
                    // Load relationships
                    // ----------------------------------------

                    $employeeLeaveType->load([
                        'employee',
                        'leaveType',
                    ]);

                    $assignedEmployees[] = $employeeLeaveType;
                }
            }

            // ========================================
            // COMMIT TRANSACTION
            // ========================================

            DB::commit();

            return response()->json([
                'isSuccess' => true,
                'message' => 'Leave type created successfully.',
                'leave_type' => $leaveType,
                'assigned_employees' => $assignedEmployees,
                'assignment_count' => count($assignedEmployees),
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'isSuccess' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
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

                // ========================================
                // LEAVE TYPE
                // ========================================

                'leave_name' => [
                    'sometimes',
                    'string',
                    'max:150',
                    'unique:leave_types,leave_name,' . $id,
                ],

                'description' => 'nullable|string',

                'max_days' => 'sometimes|integer|min:1',

                'is_paid' => 'sometimes|boolean',

                'is_active' => 'sometimes|boolean',


                // ========================================
                // EMPLOYEE ASSIGNMENTS
                // ========================================

                'employees' => 'sometimes|array',

                'employees.*.employee_id' => [
                    'required',
                    'integer',
                    'exists:employees,id',
                ],

                'employees.*.allocated_days' => [
                    'required',
                    'numeric',
                    'min:0',
                ],
            ]);


            DB::beginTransaction();


            // ========================================
            // UPDATE LEAVE TYPE
            // ========================================

            $leaveTypeData = [];

            if (array_key_exists('leave_name', $validated)) {
                $leaveTypeData['leave_name'] = $validated['leave_name'];
            }

            if (array_key_exists('description', $validated)) {
                $leaveTypeData['description'] = $validated['description'];
            }

            if (array_key_exists('max_days', $validated)) {
                $leaveTypeData['max_days'] = $validated['max_days'];
            }

            if (array_key_exists('is_paid', $validated)) {
                $leaveTypeData['is_paid'] = $validated['is_paid'];
            }

            if (array_key_exists('is_active', $validated)) {
                $leaveTypeData['is_active'] = $validated['is_active'];
            }

            if (!empty($leaveTypeData)) {
                $leaveType->update($leaveTypeData);
            }


            // ========================================
            // UPDATE EMPLOYEE ASSIGNMENTS
            // ========================================

            $assignedEmployees = [];

            if (array_key_exists('employees', $validated)) {

                /*
            |--------------------------------------------------------------------------
            | Get employee IDs submitted by the frontend
            |--------------------------------------------------------------------------
            |
            | These are the employees who are still checked/assigned.
            |
            */

                $submittedEmployeeIds = collect($validated['employees'])
                    ->pluck('employee_id')
                    ->unique()
                    ->values()
                    ->toArray();


                // ========================================
                // ARCHIVE REMOVED EMPLOYEES
                // ========================================

                /*
            |--------------------------------------------------------------------------
            | Any existing assignment that is NOT included
            | in the submitted employee list will be archived.
            |--------------------------------------------------------------------------
            */

                EmployeeLeaveType::where('leave_type_id', $leaveType->id)
                    ->where('is_archived', 0)
                    ->when(
                        count($submittedEmployeeIds) > 0,
                        function ($query) use ($submittedEmployeeIds) {
                            $query->whereNotIn('employee_id', $submittedEmployeeIds);
                        },
                        function ($query) {
                            // If employees is an empty array,
                            // archive ALL assignments.
                            $query->whereNotNull('employee_id');
                        }
                    )
                    ->update([
                        'is_active' => 0,
                        'is_archived' => 1,
                    ]);


                // ========================================
                // CREATE / UPDATE CHECKED EMPLOYEES
                // ========================================

                foreach ($validated['employees'] as $employeeData) {

                    // ----------------------------------------
                    // Check Employee
                    // ----------------------------------------

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
                            'message'   => 'Employee is inactive, archived, or does not exist.',
                            'employee_id' => $employeeData['employee_id'],
                        ], 404);
                    }


                    // ----------------------------------------
                    // Find Existing Assignment
                    // ----------------------------------------

                    $employeeLeaveType = EmployeeLeaveType::where(
                        'employee_id',
                        $employee->id
                    )
                        ->where('leave_type_id', $leaveType->id)
                        ->first();


                    // ========================================
                    // EXISTING ASSIGNMENT
                    // ========================================

                    if ($employeeLeaveType) {

                        /*
                    |--------------------------------------------------------------------------
                    | Preserve used days.
                    |
                    | If the employee was previously archived and
                    | gets checked again, restore the assignment.
                    |--------------------------------------------------------------------------
                    */

                        $usedDays = $employeeLeaveType->used_days ?? 0;

                        $allocatedDays = $employeeData['allocated_days'];

                        $remainingDays = max(
                            0,
                            $allocatedDays - $usedDays
                        );


                        $employeeLeaveType->update([
                            'allocated_days' => $allocatedDays,
                            'used_days' => $usedDays,
                            'remaining_days' => $remainingDays,
                            'is_active' => 1,
                            'is_archived' => 0,
                        ]);
                    }


                    // ========================================
                    // NEW ASSIGNMENT
                    // ========================================

                    else {

                        $employeeLeaveType = EmployeeLeaveType::create([
                            'employee_id' => $employee->id,
                            'leave_type_id' => $leaveType->id,
                            'allocated_days' => $employeeData['allocated_days'],
                            'used_days' => 0,
                            'remaining_days' => $employeeData['allocated_days'],
                            'is_active' => 1,
                            'is_archived' => 0,
                        ]);
                    }


                    // ----------------------------------------
                    // Load Relationships
                    // ----------------------------------------

                    $employeeLeaveType->load([
                        'employee',
                        'leaveType',
                    ]);

                    $assignedEmployees[] = $employeeLeaveType;
                }
            }


            // ========================================
            // COMMIT TRANSACTION
            // ========================================

            DB::commit();


            // Refresh
            $leaveType->refresh();


            // ========================================
            // RESPONSE
            // ========================================

            return response()->json([
                'isSuccess' => true,
                'message'   => 'Leave type updated successfully.',
                'leave_type' => $leaveType,
                'assigned_employees' => $assignedEmployees,
                'assignment_count' => count($assignedEmployees),
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'isSuccess' => false,
                'message'   => 'Validation failed.',
                'errors'    => $e->errors(),
            ], 422);
        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'isSuccess' => false,
                'message'   => 'Failed to update leave type.',
                'error'     => $e->getMessage(),
            ], 500);
        }
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
