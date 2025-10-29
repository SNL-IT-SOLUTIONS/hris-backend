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
    //  Get all employees
    public function getEmployees(Request $request)
    {
        $query = Employee::with(['department', 'position', 'manager', 'supervisor', 'files'])
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

        // Filter by department
        if ($request->has('department_id')) {
            $query->where('department_id', $request->department_id);
        }

        //  Filter by position
        if ($request->has('position_id')) {
            $query->where('position_id', $request->position_id);
        }

        //  Pagination
        $perPage = $request->input('per_page', 10);
        $employees = $query->paginate($perPage);

        if ($employees->isEmpty()) {
            return response()->json([
                'isSuccess' => false,
                'message'   => 'No employees found.',
            ], 404);
        }

        //  Convert file paths for each employee
        $employees->getCollection()->transform(function ($emp) {
            // Attach full file URLs
            $emp->files->transform(function ($file) {
                $file->file_path = asset($file->file_path);
                return $file;
            });

            // Convert resume if present
            $emp->resume = $emp->resume ? asset($emp->resume) : null;

            return $emp;
        });

        //  Summary counts
        $totalEmployees = Employee::where('is_archived', false)->count();
        $activeEmployees = Employee::where('is_archived', false)->where('is_active', true)->count();
        $inactiveEmployees = Employee::where('is_archived', false)->where('is_active', false)->count();

        //  Final Response
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


    public function getEmployeeById($id)
    {
        $employee = Employee::with(['department', 'position', 'manager', 'supervisor', 'files'])
            ->where('is_archived', false)
            ->find($id);

        if (!$employee) {
            return response()->json([
                'isSuccess' => false,
                'message'   => 'Employee not found.',
            ], 404);
        }

        //  Convert file paths
        $employee->files->transform(function ($file) {
            $file->file_path = asset($file->file_path);
            return $file;
        });

        // Convert resume path if exists
        $employee->resume = $employee->resume ? asset($employee->resume) : null;

        //  Final Response
        return response()->json([
            'isSuccess' => true,
            'message'   => 'Employee retrieved successfully.',
            'employee'  => $employee,
        ]);
    }





    //Create Employee
    public function createEmployee(Request $request)
    {
        $validated = $request->validate([
            // 🔹 File Upload
            '201_file.*'   => 'nullable|file|mimes:pdf,doc,docx,jpeg,png,xlsx|max:2048',
            'resume'       => 'nullable|file|mimes:pdf,doc,docx|max:2048',

            // 🔹 Basic Info
            'first_name'   => 'required|string|max:100',
            'middle_name'  => 'nullable|string|max:100',
            'last_name'    => 'required|string|max:100',
            'suffix'       => 'nullable|string|max:10',
            'email'        => 'required|email|unique:employees,email',
            'phone'        => 'nullable|string|max:50',
            'date_of_birth' => 'nullable|date',
            'place_of_birth' => 'nullable|string|max:255',
            'sex'          => 'nullable|string|max:10',
            'civil_status' => 'nullable|string|max:50',
            'height_m'  => 'nullable|numeric',
            'weight_kg' => 'nullable|numeric',
            'blood_type'   => 'nullable|string|max:5',
            'citizenship'  => 'nullable|string|max:100',

            // 🔹 Government IDs
            'gsis_no'           => 'nullable|string|max:50',
            'pagibig_no'        => 'nullable|string|max:50',
            'philhealth_no'     => 'nullable|string|max:50',
            'sss_no'            => 'nullable|string|max:50',
            'tin_no'            => 'nullable|string|max:50',
            'agency_employee_no' => 'nullable|string|max:50',

            // 🔹 Address Info
            'residential_address'  => 'nullable|string|max:255',
            'residential_zipcode'  => 'nullable|string|max:10',
            'residential_tel_no'   => 'nullable|string|max:50',
            'permanent_address'    => 'nullable|string|max:255',
            'permanent_zipcode'    => 'nullable|string|max:10',
            'permanent_tel_no'     => 'nullable|string|max:50',

            // 🔹 Family Info
            'spouse_name'           => 'nullable|string|max:255',
            'spouse_occupation'     => 'nullable|string|max:255',
            'spouse_employer'       => 'nullable|string|max:255',
            'spouse_business_address' => 'nullable|string|max:255',
            'spouse_tel_no'         => 'nullable|string|max:50',
            'father_name'           => 'nullable|string|max:255',
            'mother_name'           => 'nullable|string|max:255',
            'parents_address'       => 'nullable|string|max:255',

            // 🔹 Education (Elementary → Graduate)
            'elementary_school_name'       => 'nullable|string|max:255',
            'elementary_degree_course'     => 'nullable|string|max:255',
            'elementary_year_graduated'    => 'nullable|string|max:10',
            'elementary_highest_level'     => 'nullable|string|max:100',
            'elementary_inclusive_dates'   => 'nullable|string|max:50',
            'elementary_honors'            => 'nullable|string|max:255',

            'secondary_school_name'        => 'nullable|string|max:255',
            'secondary_degree_course'      => 'nullable|string|max:255',
            'secondary_year_graduated'     => 'nullable|string|max:10',
            'secondary_highest_level'      => 'nullable|string|max:100',
            'secondary_inclusive_dates'    => 'nullable|string|max:50',
            'secondary_honors'             => 'nullable|string|max:255',

            'vocational_school_name'       => 'nullable|string|max:255',
            'vocational_degree_course'     => 'nullable|string|max:255',
            'vocational_year_graduated'    => 'nullable|string|max:10',
            'vocational_highest_level'     => 'nullable|string|max:100',
            'vocational_inclusive_dates'   => 'nullable|string|max:50',
            'vocational_honors'            => 'nullable|string|max:255',

            'college_school_name'          => 'nullable|string|max:255',
            'college_degree_course'        => 'nullable|string|max:255',
            'college_year_graduated'       => 'nullable|string|max:10',
            'college_highest_level'        => 'nullable|string|max:100',
            'college_inclusive_dates'      => 'nullable|string|max:50',
            'college_honors'               => 'nullable|string|max:255',

            'graduate_school_name'         => 'nullable|string|max:255',
            'graduate_degree_course'       => 'nullable|string|max:255',
            'graduate_year_graduated'      => 'nullable|string|max:10',
            'graduate_highest_level'       => 'nullable|string|max:100',
            'graduate_inclusive_dates'     => 'nullable|string|max:50',
            'graduate_honors'              => 'nullable|string|max:255',

            // 🔹 Employment
            'department_id'        => 'nullable|exists:departments,id',
            'position_id'          => 'nullable|exists:position_types,id',
            'employment_type_id'   => 'nullable|exists:employment_types,id',
            'manager_id'           => 'nullable|exists:employees,id',
            'supervisor_id'        => 'nullable|exists:employees,id',
            'base_salary'          => 'nullable|numeric|min:0',
            'hire_date'            => 'nullable|date',

            // 🔹 Emergency Contact
            'emergency_contact_name'      => 'nullable|string|max:255',
            'emergency_contact_number'    => 'nullable|string|max:50',
            'emergency_contact_relation'  => 'nullable|string|max:100',

            // 🔹 Auth / System
            'password'     => 'required|string|min:8',
            'role'         => 'nullable|string|max:50',
            'is_archived'  => 'nullable|boolean',
        ]);


        // ✅ Hash password before saving
        $plainPassword = $validated['password'];
        $validated['password'] = Hash::make($plainPassword);

        // ✅ Generate automatic employee_id (e.g. EMP-2025-0001)
        $latestEmployee = Employee::latest('id')->first();
        $nextNumber = $latestEmployee ? $latestEmployee->id + 1 : 1;
        $year = date('Y');
        $employeeID = 'EMP-' . $year . '-' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
        $validated['employee_id'] = $employeeID;

        // Create employee record
        $employee = Employee::create($validated);

        // Handle multiple 201 files
        // ✅ Handle multiple 201 files correctly
        if ($request->hasFile('201_file')) {
            foreach ($request->file('201_file') as $file) {
                $filePath = $this->saveFileToPublic($file, 'employee_201');

                EmployeeFile::create([
                    'employee_id' => $employee->id,
                    'file_path'   => $filePath,
                    'file_name'   => $file->getClientOriginalName(),
                    'file_type'   => $file->getClientOriginalExtension(),
                ]);
            }
        }


        // Send welcome email (fail-safe)
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

        // Validate only critical fields (email uniqueness, ID existence, etc.)
        $request->validate([
            'email'         => 'sometimes|email|unique:employees,email,' . $employee->id,
            'department_id' => 'nullable|exists:departments,id',
            'position_id'   => 'nullable|exists:position_types,id',
            'password'      => 'nullable|string|min:8',
            '201_file.*'    => 'nullable|file|mimes:pdf,doc,docx,jpeg,png,xlsx|max:2048',
        ]);

        $data = $request->except('201_file', 'password');

        // 🔹 Handle password (hash if provided)
        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        // Update everything else dynamically
        $employee->update($data);

        // 🔹 Handle uploaded 201 files
        if ($request->hasFile('201_file')) {
            foreach ($request->file('201_file') as $file) {
                $filePath = $file->store('employee_201', 'public');

                EmployeeFile::create([
                    'employee_id' => $employee->id,
                    'file_path'   => $filePath,
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

        //  Case 1: Multiple files
        if (is_array($fileInput)) {
            $paths = [];
            foreach ($fileInput as $file) {
                $paths[] = $saveSingleFile($file);
            }
            return $paths; // Return array of paths
        }

        // Case 2: Single file
        if ($fileInput instanceof \Illuminate\Http\UploadedFile) {
            return $saveSingleFile($fileInput);
        }

        return null;
    }
}
