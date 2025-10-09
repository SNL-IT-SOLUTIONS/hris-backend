<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail;
use App\Mail\EmployeeCreated;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class EmployeeController extends Controller
{
    // ✅ Get all employees
    public function getEmployees(Request $request)
    {
        $employees = Employee::with(['department', 'position', 'manager'])
            ->where('is_archived', false)
            ->orderBy('last_name')
            ->get();

        return response()->json([
            'isSuccess' => true,
            'message' => 'Employees retrieved successfully.',
            'data' => $employees
        ]);
    }




    public function createEmployee(Request $request)
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'email' => 'required|email|unique:employees,email',
            'phone' => 'nullable|string|max:50',
            'department_id' => 'nullable|exists:departments,id',
            'position_id' => 'nullable|exists:position_types,id',
            'base_salary' => 'nullable|numeric|min:0',
            'hire_date' => 'nullable|date',
            'manager_id' => 'nullable|exists:users,id',
            'password' => 'required|string|min:8',
        ]);

        // Hash the password before saving
        $plainPassword = $validated['password'];
        $validated['password'] = Hash::make($plainPassword);

        $employee = Employee::create($validated);

        // Send welcome email
        try {
            Mail::to($employee->email)->send(new EmployeeCreated($employee, $plainPassword));
        } catch (\Exception $e) {
            Log::error('Employee email failed: ' . $e->getMessage());
        }


        return response()->json([
            'isSuccess' => true,
            'message' => 'Employee created successfully and email sent.',
            'data' => $employee
        ], 201);
    }


    // ✅ Update employee
    public function updateEmployee(Request $request, $id)
    {
        $employee = Employee::find($id);

        if (!$employee) {
            return response()->json(['isSuccess' => false, 'message' => 'Employee not found.'], 404);
        }

        $validated = $request->validate([
            'first_name' => 'sometimes|string|max:100',
            'last_name' => 'sometimes|string|max:100',
            'email' => 'sometimes|email|unique:employees,email,' . $employee->id,
            'phone' => 'nullable|string|max:50',
            'department_id' => 'nullable|exists:departments,id',
            'position_id' => 'nullable|exists:position_types,id',
            'base_salary' => 'nullable|numeric|min:0',
            'hire_date' => 'nullable|date',
            'manager_id' => 'nullable|exists:employees,id',
            'password' => 'nullable|string|min:8',
            'is_active' => 'boolean',
        ]);

        $employee->update($validated);

        return response()->json([
            'isSuccess' => true,
            'message' => 'Employee updated successfully.',
            'data' => $employee
        ]);
    }

    // ✅ Archive employee
    public function archiveEmployee($id)
    {
        $employee = Employee::find($id);

        if (!$employee) {
            return response()->json(['isSuccess' => false, 'message' => 'Employee not found.'], 404);
        }

        $employee->update(['is_archived' => true]);

        return response()->json([
            'isSuccess' => true,
            'message' => 'Employee archived successfully.'
        ]);
    }
}
