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
        $employees = Employee::with(['department', 'position', 'manager', 'supervisor'])
            ->where('is_archived', false)
            ->orderBy('last_name')
            ->get();

        return response()->json([
            'isSuccess' => true,
            'message' => 'Employees retrieved successfully.',
            'employees' => $employees
        ]);
    }




    public function createEmployee(Request $request)
    {
        $validated = $request->validate([
            '201_file' => 'required|file|mimes:pdf,doc,docx,jpg,png|max:2048', // updated to handle file upload
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'email' => 'required|email|unique:employees,email',
            'phone' => 'nullable|string|max:50',
            'department_id' => 'nullable|exists:departments,id',
            'position_id' => 'nullable|exists:position_types,id',
            'base_salary' => 'nullable|numeric|min:0',
            'hire_date' => 'nullable|date',
            'manager_id' => 'nullable|exists:employees,id',
            'supervisor_id' => 'nullable|exists:employees,id',
            'password' => 'required|string|min:8',
        ]);

        // Save file if uploaded
        $filePath = $this->saveFileToPublic($request, '201_file', 'employee_201');
        if ($filePath) {
            $validated['201_file'] = $filePath;
        }

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
            'employees' => $employee
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
            '201_file' => 'nullable|file|mimes:pdf,doc,docx,jpg,png|max:2048',
            'first_name' => 'sometimes|string|max:100',
            'last_name' => 'sometimes|string|max:100',
            'email' => 'sometimes|email|unique:employees,email,' . $employee->id,
            'phone' => 'nullable|string|max:50',
            'department_id' => 'nullable|exists:departments,id',
            'position_id' => 'nullable|exists:position_types,id',
            'base_salary' => 'nullable|numeric|min:0',
            'hire_date' => 'nullable|date',
            'manager_id' => 'nullable|exists:employees,id',
            'supervisor_id' => 'nullable|exists:employees,id',
            'password' => 'nullable|string|min:8',
            'is_active' => 'boolean',
        ]);

        // If a new file is uploaded, save and replace the old one
        $filePath = $this->saveFileToPublic($request, '201_file', 'employee_201');
        if ($filePath) {
            // Delete old file if exists
            if ($employee->{'201_file'} && file_exists(public_path($employee->{'201_file'}))) {
                unlink(public_path($employee->{'201_file'}));
            }
            $validated['201_file'] = $filePath;
        }

        // Update password if provided
        if (!empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $employee->update($validated);

        return response()->json([
            'isSuccess' => true,
            'message' => 'Employee updated successfully.',
            'employees' => $employee
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

    //HELPERS
    private function saveFileToPublic(Request $request, $field, $prefix)
    {
        if ($request->hasFile($field)) {
            $file = $request->file($field);

            // Directory inside /public
            $directory = public_path('hris_files');
            if (!file_exists($directory)) {
                mkdir($directory, 0755, true);
            }

            // Generate filename: prefix + unique id + original extension
            $filename = $prefix . '_' . uniqid() . '.' . $file->getClientOriginalExtension();

            // Move file to public/hris_files
            $file->move($directory, $filename);

            // Return relative path (to store in DB)
            return 'hris_files/' . $filename;
        }

        return null;
    }
}
