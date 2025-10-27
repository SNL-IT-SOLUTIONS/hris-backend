<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use App\Mail\EmployeeCreated;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use App\Models\Leave;
use App\Models\EmployeeFile;

class EmployeeController extends Controller
{
    // ✅ Get all employees
    public function getEmployees(Request $request)
    {
        $query = Employee::with(['department', 'position', 'manager', 'supervisor'])
            ->where('is_archived', false);

        // 🔎 Search
        if ($request->has('search') && $request->search != '') {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'LIKE', "%$search%")
                    ->orWhere('last_name', 'LIKE', "%$search%")
                    ->orWhere('email', 'LIKE', "%$search%")
                    ->orWhereHas('department', function ($dq) use ($search) {
                        $dq->where('department_name', 'LIKE', "%$search%");
                    })
                    ->orWhereHas('position', function ($pq) use ($search) {
                        $pq->where('position_name', 'LIKE', "%$search%");
                    });
            });
        }

        //  Filter by department
        if ($request->has('department_id')) {
            $query->where('department_id', $request->department_id);
        }

        //  Filter by position
        if ($request->has('position_id')) {
            $query->where('position_id', $request->position_id);
        }

        // 🔢 Normal Pagination (default: 10 per page)
        $perPage = $request->input('per_page', 10);
        $employees = $query->paginate($perPage);

        if ($employees->isEmpty()) {
            return response()->json([
                'isSuccess' => false,
                'message'   => 'No employees found.',
            ], 404);
        }

        // 🖼️ Add full URLs for file paths
        $employees->getCollection()->transform(function ($emp) {
            // Convert 201_file to full URL
            $emp->{'201_file'} = $emp->{'201_file'}
                ? asset('storage/' . $emp->{'201_file'})
                : null;

            // Convert resume to full URL
            $emp->resume = $emp->resume
                ? asset('storage/' . $emp->resume)
                : null;

            return $emp;
        });

        // 📊 Summary counts
        $totalEmployees = Employee::where('is_archived', false)->count();
        $activeEmployees = Employee::where('is_archived', false)->where('is_active', true)->count();
        $inactiveEmployees = Employee::where('is_archived', false)->where('is_active', false)->count();

        // ✅ Final Response
        return response()->json([
            'isSuccess' => true,
            'message'   => 'Employees retrieved successfully.',
            'employees' => $employees->items(),
            'summary'   => [
                'total_employees'    => $totalEmployees,
                'active_employees'   => $activeEmployees,
                'inactive_employees' => $inactiveEmployees,
            ],
            'pagination' => [
                'current_page' => $employees->currentPage(),
                'per_page'     => $employees->perPage(),
                'total'        => $employees->total(),
                'last_page'    => $employees->lastPage(),
            ],
        ]);
    }




    //Create Employee
    public function createEmployee(Request $request)
    {
        $validated = $request->validate([
            '201_file.*'   => 'required|file|mimes:pdf,doc,docx,jpeg,png,xlsx|max:2048',
            'first_name'   => 'required|string|max:100',
            'last_name'    => 'required|string|max:100',
            'email'        => 'required|email|unique:employees,email',
            'phone'        => 'nullable|string|max:50',
            'department_id' => 'nullable|exists:departments,id',
            'position_id'  => 'nullable|exists:position_types,id',
            'base_salary'  => 'nullable|numeric|min:0',
            'hire_date'    => 'nullable|date',
            'manager_id'   => 'nullable|exists:employees,id',
            'supervisor_id' => 'nullable|exists:employees,id',
            'password'     => 'required|string|min:8',
        ]);

        // ✅ Hash password before saving
        $plainPassword = $validated['password'];
        $validated['password'] = Hash::make($plainPassword);

        // ✅ Create employee record
        $employee = Employee::create($validated);

        // ✅ Handle multiple 201 files
        if ($request->hasFile('201_file')) {
            foreach ($request->file('201_file') as $file) {
                // use your existing saveFileToPublic() method
                $filePath = $this->saveFileToPublic($request, '201_file', 'employee_201');

                EmployeeFile::create([
                    'employee_id' => $employee->id,
                    'file_path'   => $filePath,
                    'file_name'   => $file->getClientOriginalName(),
                    'file_type'   => $file->getClientOriginalExtension(),
                ]);
            }
        }

        // ✅ Send welcome email (fail-safe)
        try {
            Mail::to($employee->email)->send(new EmployeeCreated($employee, $plainPassword));
        } catch (\Exception $e) {
            Log::error('Employee email failed: ' . $e->getMessage());
        }

        return response()->json([
            'isSuccess' => true,
            'message'   => 'Employee created successfully and email sent.',
            'employee'  => $employee
        ], 201);
    }





    public function updateEmployee(Request $request, $id)
    {
        $employee = Employee::find($id);

        if (!$employee) {
            return response()->json(['isSuccess' => false, 'message' => 'Employee not found.'], 404);
        }

        $validated = $request->validate([
            '201_file.*'     => 'file|mimes:pdf,doc,docx,jpeg,png,xlsx|max:2048',
            'first_name'     => 'sometimes|string|max:100',
            'last_name'      => 'sometimes|string|max:100',
            'email'          => 'sometimes|email|unique:employees,email,' . $employee->id,
            'phone'          => 'nullable|string|max:50',
            'department_id'  => 'nullable|exists:departments,id',
            'position_id'    => 'nullable|exists:position_types,id',
            'base_salary'    => 'nullable|numeric|min:0',
            'hire_date'      => 'nullable|date',
            'manager_id'     => 'nullable|exists:employees,id',
            'supervisor_id'  => 'nullable|exists:employees,id',
            'password'       => 'nullable|string|min:8',
            'is_active'      => 'boolean',
        ]);

        // ✅ Update password if provided
        if (!empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        // ✅ Update the main employee info first (no file column)
        $employee->update(collect($validated)->except('201_file')->toArray());

        // ✅ If new 201 files are uploaded
        if ($request->hasFile('201_file')) {
            $files = $request->file('201_file');
            $filePaths = $this->saveFileToPublic($files, 'employee_201');

            foreach ((array) $filePaths as $index => $path) {
                $file = is_array($files) ? $files[$index] : $files;
                EmployeeFile::create([
                    'employee_id' => $employee->id,
                    'file_path'   => $path,
                    'file_name'   => $file->getClientOriginalName(),
                    'file_type'   => $file->getClientOriginalExtension(),
                ]);
            }
        }

        return response()->json([
            'isSuccess' => true,
            'message' => 'Employee updated successfully.',
            'employee' => $employee->load('files'),
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


    //Request Leave
    public function requestLeave(Request $request)
    {
        try {
            // ✅ Validate input
            $validated = $request->validate([
                'employee_id'   => 'required|exists:employees,id',
                'leave_type'    => 'required|string|max:100', // e.g., Vacation, Sick, Emergency
                'start_date'    => 'required|date|after_or_equal:today',
                'end_date'      => 'required|date|after_or_equal:start_date',
                'reason'        => 'nullable|string|max:500',
            ]);

            // Optional: Calculate number of days
            $days = (new \DateTime($validated['start_date']))->diff(new \DateTime($validated['end_date']))->days + 1;
            $validated['total_days'] = $days;
            $validated['status'] = 'Pending'; // default status

            // ✅ Save to DB (assuming you have a Leave model + table)
            $leave = Leave::create($validated);

            Log::info("Leave request created for employee ID {$validated['employee_id']} ({$days} days)");

            return response()->json([
                'isSuccess' => true,
                'message'   => 'Leave request submitted successfully.',
                'leave'     => $leave,
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error submitting leave request: ' . $e->getMessage());
            return response()->json([
                'isSuccess' => false,
                'message'   => 'Failed to submit leave request.',
            ], 500);
        }
    }


    //HELPERS
    private function saveFileToPublic($fileInput, $prefix)
    {
        $directory = public_path('hris_files');
        if (!file_exists($directory)) {
            mkdir($directory, 0755, true);
        }

        $saveSingleFile = function ($file) use ($directory, $prefix) {
            $filename = $prefix . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $file->move($directory, $filename);
            return 'hris_files/' . $filename;
        };

        // 🔹 Case 1: Multiple files
        if (is_array($fileInput)) {
            $paths = [];
            foreach ($fileInput as $file) {
                $paths[] = $saveSingleFile($file);
            }
            return $paths; // Return array of paths
        }

        // 🔹 Case 2: Single file
        if ($fileInput instanceof \Illuminate\Http\UploadedFile) {
            return $saveSingleFile($fileInput);
        }

        return null;
    }
}
