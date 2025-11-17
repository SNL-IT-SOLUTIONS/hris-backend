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
use App\Models\BenefitType;
use App\Models\AllowanceType;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

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
            'employeeBenefits.benefit',
            'employeeAllowances.allowance'
        ])->where('is_archived', 0);

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'LIKE', "%{$search}%")
                    ->orWhere('last_name', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%")
                    ->orWhereHas('department', fn($dq) => $dq->where('department_name', 'LIKE', "%{$search}%"))
                    ->orWhereHas('position', fn($pq) => $pq->where('position_name', 'LIKE', "%{$search}%"))
                    ->orWhereHas('employeeBenefits.benefit', fn($bq) => $bq->where('benefit_name', 'LIKE', "%{$search}%"))
                    ->orWhereHas('employeeAllowances.allowance', fn($aq) => $aq->where('type_name', 'LIKE', "%{$search}%"));
            });
        }

        // Filters
        foreach (['department_id', 'position_id', 'benefit_id', 'allowance_id'] as $filter) {
            if ($request->filled($filter)) {
                switch ($filter) {
                    case 'benefit_id':
                        $query->whereHas('employeeBenefits', fn($q) => $q->where('benefit_type_id', $request->benefit_id));
                        break;
                    case 'allowance_id':
                        $query->whereHas('employeeAllowances', fn($q) => $q->where('allowance_type_id', $request->allowance_id));
                        break;
                    default:
                        $query->where($filter, $request->$filter);
                }
            }
        }

        $perPage = $request->input('per_page', 5);
        $employees = $query->paginate($perPage);

        if ($employees->isEmpty()) {
            return response()->json([
                'isSuccess' => false,
                'message'   => 'No employees found.',
            ], 404);
        }

        // Transform the paginated collection
        $employees->getCollection()->transform(function ($emp) {
            // File URLs
            $emp->files->transform(fn($file) => $file->file_path = asset($file->file_path) ?: $file);

            // Resume URL
            $emp->resume = $emp->resume ? asset($emp->resume) : null;

            // Benefits
            $emp->benefits = $emp->employeeBenefits->map(fn($eb) => [
                'id'           => $eb->benefit->id,
                'benefit_name' => $eb->benefit->benefit_name,
                'category'     => $eb->benefit->category,
                'amount'       => $eb->amount,
            ]);

            // Allowances
            $emp->allowances = $emp->employeeAllowances->map(fn($ea) => [
                'id'          => $ea->allowance->id,
                'type_name'   => $ea->allowance->type_name,
                'amount'      => $ea->amount,
                'description' => $ea->allowance->description,
            ]);

            return $emp;
        });

        return response()->json([
            'isSuccess'  => true,
            'message'    => 'Employees retrieved successfully.',
            'employees'  => $employees->items(),
            'summary'    => [
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
                'employeeBenefits.benefit:id,benefit_name,category',   // include pivot and type
                'employeeAllowances.allowance:id,type_name,description' // include pivot and type
            ])
                ->where('is_archived', false)
                ->find($id);

            if (!$employee) {
                return response()->json([
                    'isSuccess' => false,
                    'message'   => 'Employee not found.',
                ], 404);
            }

            // Convert file paths to full URLs
            $employee->files->transform(function ($file) {
                $file->file_path = asset('storage/' . $file->file_path);
                return $file;
            });

            // Convert resume path if it exists
            $employee->resume = $employee->resume ? asset('storage/' . $employee->resume) : null;

            // Include full name for manager and supervisor
            $employee->manager_name = $employee->manager ? "{$employee->manager->first_name} {$employee->manager->last_name}" : null;
            $employee->supervisor_name = $employee->supervisor ? "{$employee->supervisor->first_name} {$employee->supervisor->last_name}" : null;

            // Transform benefits with amount
            $employee->benefits = $employee->employeeBenefits->map(fn($eb) => [
                'id'           => $eb->benefit->id,
                'benefit_name' => $eb->benefit->benefit_name,
                'category'     => $eb->benefit->category,
                'amount'       => $eb->amount,
            ]);

            // Transform allowances with amount
            $employee->allowances = $employee->employeeAllowances->map(fn($ea) => [
                'id'          => $ea->allowance->id,
                'type_name'   => $ea->allowance->type_name,
                'description' => $ea->allowance->description,
                'amount'      => $ea->amount,
            ]);

            // Optional cleanup: hide unnecessary nested objects
            unset($employee->manager, $employee->supervisor, $employee->employeeBenefits, $employee->employeeAllowances);

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

            // Benefit & Allowance amounts
            'benefits' => 'nullable|array',
            'benefits.*.benefit_type_id' => 'required_with:benefits|exists:benefit_types,id',
            'benefits.*.amount' => 'nullable|numeric|min:0',
            'allowances' => 'nullable|array',
            'allowances.*.allowance_type_id' => 'required_with:allowances|exists:allowance_types,id',
            'allowances.*.amount' => 'nullable|numeric|min:0',

            // Auth / System
            'password'     => 'required|string|min:8',
            'role'         => 'nullable|string|max:50',
            'is_archived'  => 'nullable|numeric',
            'is_interviewer' => 'nullable|boolean',
        ]);

        // Hash password
        $plainPassword = $validated['password'];
        $validated['password'] = Hash::make($plainPassword);

        // Auto-generate employee ID
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

        // Insert benefit and allowance values
        if (!empty($validated['benefits'])) {
            foreach ($validated['benefits'] as $benefit) {
                DB::table('employee_benefit')->updateOrInsert(
                    [
                        'employee_id' => $employee->id,
                        'benefit_type_id' => $benefit['benefit_type_id'],
                    ],
                    [
                        'amount' => $benefit['amount'] ?? null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }

        if (!empty($validated['allowances'])) {
            foreach ($validated['allowances'] as $allowance) {
                DB::table('employee_allowance')->updateOrInsert(
                    [
                        'employee_id' => $employee->id,
                        'allowance_type_id' => $allowance['allowance_type_id'],
                    ],
                    [
                        'amount' => $allowance['amount'] ?? null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
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
            'employee' => $employee->load('benefits', 'allowances', 'files'),
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

        $validator = Validator::make($request->all(), [
            'employee_id'   => 'sometimes|string|unique:employees,employee_id,',
            'email'         => 'sometimes|email|unique:employees,email,' . $employee->id,
            'department_id' => 'nullable|exists:departments,id',
            'position_id'   => 'nullable|exists:position_types,id',
            'password'      => 'nullable|string|min:8',
            '201_file.*'    => 'nullable|file|mimes:pdf,doc,docx,jpeg,png,xlsx|max:2048',

            // Validate allowances and benefits arrays with amount
            'allowances'           => 'nullable|array',
            'allowances.*.id'      => 'required_with:allowances|exists:allowance_types,id',
            'allowances.*.amount'  => 'required_with:allowances|numeric|min:0',
            'benefits'             => 'nullable|array',
            'benefits.*.id'        => 'required_with:benefits|exists:benefit_types,id',
            'benefits.*.amount'    => 'required_with:benefits|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors()
            ], 422);
        }

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

        // Handle Allowances (with amounts)
        if ($request->has('allowances')) {
            $allowances = $request->allowances;

            // Clear old data before re-inserting
            $employee->employeeAllowances()->delete();

            foreach ($allowances as $allowance) {
                $employee->employeeAllowances()->create([
                    'allowance_type_id' => $allowance['id'],
                    'amount'            => $allowance['amount'],
                ]);
            }
        }

        // Handle Benefits (with amounts)
        if ($request->has('benefits')) {
            $benefits = $request->benefits;

            // Clear old data before re-inserting
            $employee->employeeBenefits()->delete();

            foreach ($benefits as $benefit) {
                $employee->employeeBenefits()->create([
                    'benefit_type_id' => $benefit['id'],
                    'amount'          => $benefit['amount'],
                ]);
            }
        }

        return response()->json([
            'isSuccess' => true,
            'message'   => 'Employee updated successfully with allowances and benefits.',
            'employee'  => $employee->load(['files', 'employeeAllowances.allowance', 'employeeBenefits.benefit']),
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
