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
        $query = Employee::with([
            'department',
            'position',
            'manager',
            'supervisor',
            'files',
            'benefits',
            'allowances' //  added
        ])->where('is_archived', 0);

        // Search
        if ($request->has('search') && $request->search != '') {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'LIKE', "%$search%")
                    ->orWhere('last_name', 'LIKE', "%$search%")
                    ->orWhere('email', 'LIKE', "%$search%")
                    ->orWhereHas('department', fn($dq) => $dq->where('department_name', 'LIKE', "%$search%"))
                    ->orWhereHas('position', fn($pq) => $pq->where('position_name', 'LIKE', "%$search%"))
                    ->orWhereHas('benefits', fn($bq) => $bq->where('benefit_name', 'LIKE', "%$search%"))
                    ->orWhereHas('allowances', fn($aq) => $aq->where('type_name', 'LIKE', "%$search%")); // new search
            });
        }

        // Filter by department
        if ($request->filled('department_id')) {
            $query->where('department_id', $request->department_id);
        }

        //  Filter by position
        if ($request->filled('position_id')) {
            $query->where('position_id', $request->position_id);
        }

        //  Filter by benefit
        if ($request->filled('benefit_id')) {
            $query->whereHas('benefits', fn($q) => $q->where('benefit_types.id', $request->benefit_id));
        }

        //  Filter by allowance
        if ($request->filled('allowance_id')) {
            $query->whereHas('allowances', fn($q) => $q->where('allowance_types.id', $request->allowance_id));
        }

        $perPage = $request->input('per_page', 5);
        $employees = $query->paginate($perPage);

        if ($employees->isEmpty()) {
            return response()->json([
                'isSuccess' => false,
                'message'   => 'No employees found.',
            ], 404);
        }

        $employees->getCollection()->transform(function ($emp) {
            //  Attach file URLs
            $emp->files->transform(function ($file) {
                $file->file_path = asset($file->file_path);
                return $file;
            });

            // Resume URL
            $emp->resume = $emp->resume ? asset($emp->resume) : null;

            // Transform benefits
            $emp->benefits = $emp->benefits->map(fn($b) => [
                'id' => $b->id,
                'benefit_name' => $b->benefit_name,
                'category' => $b->category,
                'rate' => $b->rate,
            ]);

            //  Transform allowances
            $emp->allowances = $emp->allowances->map(fn($a) => [
                'id' => $a->id,
                'type_name' => $a->type_name,
                'value' => $a->value,
                'description' => $a->description,
            ]);

            return $emp;
        });

        // Summary counts
        return response()->json([
            'isSuccess' => true,
            'message'   => 'Employees retrieved successfully.',
            'employees' => $employees->items(),
            'summary'   => [
                'total_employees'    => Employee::where('is_archived', false)->count(),
                'active_employees'   => Employee::where('is_archived', false)->where('is_active', true)->count(),
                'inactive_employees' => Employee::where('is_archived', true)->count(),
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
        try {
            $employee = Employee::with([
                'department:id,department_name',
                'position:id,position_name',
                'manager:id,first_name,last_name',
                'supervisor:id,first_name,last_name',
                'files',
                'benefits:id,benefit_name,description,rate',
                'allowances:id,type_name,description,value'
            ])
                ->where('is_archived', false)
                ->find($id);

            if (!$employee) {
                return response()->json([
                    'isSuccess' => false,
                    'message'   => 'Employee not found.',
                ], 404);
            }

            //  Convert file paths to full URLs
            $employee->files->transform(function ($file) {
                $file->file_path = asset('storage/' . $file->file_path);
                return $file;
            });

            // Convert resume path if it exists
            $employee->resume = $employee->resume ? asset('storage/' . $employee->resume) : null;

            //  Include full name for manager and supervisor
            $employee->manager_name = $employee->manager ? "{$employee->manager->first_name} {$employee->manager->last_name}" : null;
            $employee->supervisor_name = $employee->supervisor ? "{$employee->supervisor->first_name} {$employee->supervisor->last_name}" : null;

            //  Optional cleanup: hide unnecessary nested objects
            unset($employee->manager, $employee->supervisor);

            return response()->json([
                'isSuccess' => true,
                'message'   => 'Employee retrieved successfully.',
                'employee'  => $employee,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching employee by ID: ' . $e->getMessage());
            return response()->json([
                'isSuccess' => false,
                'message'   => 'Failed to retrieve employee details.',
                'error'     => $e->getMessage(),
            ], 500);
        }
    }





    //Create Employee
    public function createEmployee(Request $request)
    {
        $validated = $request->validate([
            // File Upload
            '201_file.*'   => 'nullable|file|mimes:pdf,doc,docx,jpeg,png,xlsx|max:2048',
            'resume'       => 'nullable|file|mimes:pdf,doc,docx|max:2048',

            // Basic Info
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
            'height_m'     => 'nullable|numeric',
            'weight_kg'    => 'nullable|numeric',
            'blood_type'   => 'nullable|string|max:5',
            'citizenship'  => 'nullable|string|max:100',

            // Government IDs
            'gsis_no'           => 'nullable|string|max:50',
            'pagibig_no'        => 'nullable|string|max:50',
            'philhealth_no'     => 'nullable|string|max:50',
            'sss_no'            => 'nullable|string|max:50',
            'tin_no'            => 'nullable|string|max:50',
            'agency_employee_no' => 'nullable|string|max:50',

            // Address Info
            'residential_address' => 'nullable|string|max:255',
            'residential_zipcode' => 'nullable|string|max:10',
            'residential_tel_no'  => 'nullable|string|max:50',
            'permanent_address'   => 'nullable|string|max:255',
            'permanent_zipcode'   => 'nullable|string|max:10',
            'permanent_tel_no'    => 'nullable|string|max:50',

            // Family Info
            'spouse_name'           => 'nullable|string|max:255',
            'spouse_occupation'     => 'nullable|string|max:255',
            'spouse_employer'       => 'nullable|string|max:255',
            'spouse_business_address' => 'nullable|string|max:255',
            'spouse_tel_no'         => 'nullable|string|max:50',
            'father_name'           => 'nullable|string|max:255',
            'mother_name'           => 'nullable|string|max:255',
            'parents_address'       => 'nullable|string|max:255',

            // Education
            'elementary_school_name' => 'nullable|string|max:255',
            'elementary_degree_course' => 'nullable|string|max:255',
            'elementary_year_graduated' => 'nullable|string|max:10',
            'elementary_highest_level' => 'nullable|string|max:100',
            'elementary_inclusive_dates' => 'nullable|string|max:50',
            'elementary_honors' => 'nullable|string|max:255',

            'secondary_school_name' => 'nullable|string|max:255',
            'secondary_degree_course' => 'nullable|string|max:255',
            'secondary_year_graduated' => 'nullable|string|max:10',
            'secondary_highest_level' => 'nullable|string|max:100',
            'secondary_inclusive_dates' => 'nullable|string|max:50',
            'secondary_honors' => 'nullable|string|max:255',

            'vocational_school_name' => 'nullable|string|max:255',
            'vocational_degree_course' => 'nullable|string|max:255',
            'vocational_year_graduated' => 'nullable|string|max:10',
            'vocational_highest_level' => 'nullable|string|max:100',
            'vocational_inclusive_dates' => 'nullable|string|max:50',
            'vocational_honors' => 'nullable|string|max:255',

            'college_school_name' => 'nullable|string|max:255',
            'college_degree_course' => 'nullable|string|max:255',
            'college_year_graduated' => 'nullable|string|max:10',
            'college_highest_level' => 'nullable|string|max:100',
            'college_inclusive_dates' => 'nullable|string|max:50',
            'college_honors' => 'nullable|string|max:255',

            'graduate_school_name' => 'nullable|string|max:255',
            'graduate_degree_course' => 'nullable|string|max:255',
            'graduate_year_graduated' => 'nullable|string|max:10',
            'graduate_highest_level' => 'nullable|string|max:100',
            'graduate_inclusive_dates' => 'nullable|string|max:50',
            'graduate_honors' => 'nullable|string|max:255',

            // Employment
            'department_id'        => 'nullable|exists:departments,id',
            'position_id'          => 'nullable|exists:position_types,id',
            'employment_type_id'   => 'nullable|exists:employment_types,id',
            'manager_id'           => 'nullable|exists:employees,id',
            'supervisor_id'        => 'nullable|exists:employees,id',
            'base_salary'          => 'nullable|numeric|min:0',
            'hire_date'            => 'nullable|date',

            // Emergency Contact
            'emergency_contact_name'     => 'nullable|string|max:255',
            'emergency_contact_number'   => 'nullable|string|max:50',
            'emergency_contact_relation' => 'nullable|string|max:100',

            // Benefits
            'benefit_type_ids'   => 'nullable|array',
            'benefit_type_ids.*' => 'exists:benefit_types,id',

            // NEW Allowance field
            'allowance_type_ids'   => 'nullable|array',
            'allowance_type_ids.*' => 'exists:allowance_types,id',

            // Auth / System
            'password'     => 'required|string|min:8',
            'role'         => 'nullable|string|max:50',
            'is_archived'  => 'nullable|numeric',
            'is_interviewer' => 'nullable|boolean',
        ]);

        // Hash password
        $plainPassword = $validated['password'];
        $validated['password'] = Hash::make($plainPassword);

        //  Auto-generate employee ID
        $latestEmployee = Employee::latest('id')->first();
        $nextNumber = $latestEmployee ? $latestEmployee->id + 1 : 1;
        $validated['employee_id'] = 'EMP-' . date('Y') . '-' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);

        // Handle resume file upload
        if ($request->hasFile('resume')) {
            $validated['resume'] = $this->saveFileToPublic($request->file('resume'), 'employee_resumes');
        }

        // Create employee record
        $employee = Employee::create($validated);

        // Attach Benefits (many-to-many pivot)
        if (!empty($validated['benefit_type_ids'])) {
            $employee->benefits()->sync($validated['benefit_type_ids']);
        }

        if (!empty($validated['allowance_type_ids'])) {
            $employee->allowances()->sync($validated['allowance_type_ids']);
        }


        // Handle 201 files
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

        // Send Welcome Email (fail-safe)
        try {
            Mail::to($employee->email)->send(new EmployeeCreated($employee, $plainPassword));
        } catch (\Exception $e) {
            Log::error('Employee email failed: ' . $e->getMessage());
        }

        return response()->json([
            'isSuccess' => true,
            'message' => 'Employee created successfully and email sent.',
            'employee' => $employee->load('benefits', 'files'),
        ], 201);
    }

    public function updateEmployee(Request $request, $id)
    {
        $employee = Employee::find($id);

        if (!$employee) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Employee not found.'
            ], 404);
        }

        $request->validate([
            'email'         => 'sometimes|email|unique:employees,email,' . $employee->id,
            'department_id' => 'nullable|exists:departments,id',
            'position_id'   => 'nullable|exists:position_types,id',
            'password'      => 'nullable|string|min:8',
            '201_file.*'    => 'nullable|file|mimes:pdf,doc,docx,jpeg,png,xlsx|max:2048',

            // Pivot relationships
            'benefits'      => 'nullable|array',
            'benefits.*'    => 'exists:benefit_types,id',

            'allowances'    => 'nullable|array',
            'allowances.*'  => 'exists:allowance_types,id',

            'benefits_na' => 'nullable|boolean',
            'allowances_na' => 'nullable|boolean',
        ]);

        $data = $request->except(['201_file', 'password', 'benefits', 'allowances']);

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $employee->update($data);

        // Handle 201 Files
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

        // Handle Benefits (even if empty array, clear all)
        if ($request->has('benefits')) {
            $employee->benefits()->sync($request->benefits ?? []);
        }

        // Handle Allowances (same logic)
        if ($request->has('allowances')) {
            $employee->allowances()->sync($request->allowances ?? []);
        }

        return response()->json([
            'isSuccess' => true,
            'message'   => 'Employee updated successfully.',
            'employee'  => $employee->load(['files', 'benefits', 'allowances']),
        ]);
    }



    // Archive employee
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
            // Validate input
            $validated = $request->validate([
                'employee_id'   => 'required|exists:employees,id',
                'leave_type'    => 'required|string|max:100',
                'start_date'    => 'required|date|after_or_equal:today',
                'end_date'      => 'required|date|after_or_equal:start_date',
                'reason'        => 'nullable|string|max:500',
            ]);

            // Optional: Calculate number of days
            $days = (new \DateTime($validated['start_date']))->diff(new \DateTime($validated['end_date']))->days + 1;
            $validated['total_days'] = $days;
            $validated['status'] = 'Pending';

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
