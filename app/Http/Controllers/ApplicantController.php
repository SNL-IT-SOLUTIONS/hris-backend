<?php

namespace App\Http\Controllers;

use App\Models\Applicant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\Employee;
use Illuminate\Support\Facades\Mail;
use App\Mail\EmployeeCreated;
use Illuminate\Support\Facades\Hash;
use Exception;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;


class ApplicantController extends Controller
{
    /**
     * Create a new applicant (job application submission)
     */
    public function createApplicant(Request $request)
    {
        try {
            $validated = $request->validate([
                'job_posting_id'       => 'required|exists:job_postings,id',
                'first_name'           => 'required|string|max:100',
                'last_name'            => 'required|string|max:100',
                'email'                => 'required|email|max:150',
                'phone_number'         => 'nullable|string|max:20',
                'resume'               => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:2048',
                'cover_letter'         => 'nullable|string',
                'linkedin_profile'     => 'nullable|string|max:255',
                'portfolio_website'    => 'nullable|string|max:255',
                'salary_expectations'  => 'nullable|string|max:100',
                'available_start_date' => 'nullable|string|max:100',
                'experience_years'     => 'nullable|integer|min:0',
            ]);

            //  Upload resume using your helper
            $validated['resume'] = $this->saveFileToPublic($request, 'resume', 'resume');

            // Default values
            $validated['stage'] = 'new_application';
            $validated['is_archived'] = false;

            $applicant = Applicant::create($validated);

            return response()->json([
                'isSuccess' => true,
                'message' => 'Application submitted successfully!',
                'data' => $applicant,
            ], 201);
        } catch (ValidationException $e) {
            //  Show validation errors in response
            return response()->json([
                'isSuccess' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            // Catch all other errors
            Log::error('Error creating applicant: ' . $e->getMessage());

            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to submit application.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }



    /**
     *  Get all applicants
     */
    public function getApplicants(Request $request)
    {
        try {
            $applicants = Applicant::with('jobPosting')
                ->where('is_archived', false)
                ->where('stage', '!=', 'hired')
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'isSuccess' => true,
                'message' => 'Applicants retrieved successfully.',
                'data' => $applicants,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching applicants: ' . $e->getMessage());
            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to retrieve applicants.',
            ], 500);
        }
    }

    /**
     *  Get a single applicant by ID
     */
    public function getApplicantById($id)
    {
        try {
            $applicant = Applicant::with('jobPosting')
                ->where('id', $id)
                ->where('is_archived', false)
                ->firstOrFail();

            return response()->json([
                'isSuccess' => true,
                'message'   => 'Applicant retrieved successfully.',
                'data'      => $applicant,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching applicant: ' . $e->getMessage());

            return response()->json([
                'isSuccess' => false,
                'message'   => 'Failed to retrieve applicant.',
            ], 500);
        }
    }


    /**
     * Get all hired applicants
     */
    public function getHiredApplicants()
    {
        try {
            $hiredApplicants = Applicant::with(['jobPosting.department'])
                ->where('stage', 'hired')
                ->where('is_archived', false)
                ->orderBy('updated_at', 'desc')
                ->get();


            return response()->json([
                'isSuccess' => true,
                'message'   => 'Hired applicants retrieved successfully.',
                'data'      => $hiredApplicants->map(function ($applicant) {
                    return [
                        'id'                    => $applicant->id,
                        'first_name'            => $applicant->first_name,
                        'last_name'             => $applicant->last_name,
                        'email'                 => $applicant->email,
                        'phone_number'          => $applicant->phone_number,
                        'resume'                => $applicant->resume ? asset('storage/' . $applicant->resume) : null,
                        'cover_letter'          => $applicant->cover_letter,
                        'linkedin_profile'      => $applicant->linkedin_profile,
                        'portfolio_website'     => $applicant->portfolio_website,
                        'salary_expectations'   => $applicant->salary_expectations,
                        'available_start_date'  => $applicant->available_start_date,
                        'experience_years'      => $applicant->experience_years,
                        'stage'                 => $applicant->stage,
                        'created_at'            => $applicant->created_at,
                        'updated_at'            => $applicant->updated_at,


                        'department_name' => $applicant->jobPosting?->department?->department_name ?? null,

                        'job_posting'           => $applicant->jobPosting,
                    ];
                }),
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching hired applicants: ' . $e->getMessage());
            return response()->json([
                'isSuccess' => false,
                'message'   => 'Failed to retrieve hired applicants.',
            ], 500);
        }
    }



    public function getHiredApplicantById($id)
    {
        try {
            $applicant = Applicant::with('jobPosting')
                ->where('id', $id)
                ->where('stage', 'hired')
                ->where('is_archived', false)
                ->firstOrFail();

            return response()->json([
                'isSuccess' => true,
                'message'   => 'Hired applicant retrieved successfully.',
                'data'      => $applicant,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching hired applicant: ' . $e->getMessage());
            return response()->json([
                'isSuccess' => false,
                'message'   => 'Failed to retrieve hired applicant.',
            ], 500);
        }
    }

    public function hireApplicant(Request $request, $applicantId)
    {
        try {
            // Validate form data
            $validated = $request->validate([
                '201_file'       => 'nullable|file|mimes:pdf,doc,docx,jpg,png|max:2048',
                'department_id'  => 'required|exists:departments,id',
                'position_id'    => 'required|exists:position_types,id',
                'base_salary'    => 'required|numeric|min:0',
                'hire_date'      => 'required|date',
                'password'       => 'required|string|min:8',
                'manager_id'     => 'nullable|exists:employees,id',
                'supervisor_id'  => 'nullable|exists:employees,id',
            ]);

            // Find applicant record
            $applicant = Applicant::findOrFail($applicantId);

            // Save 201 file if provided
            $filePath = $this->saveFileToPublic($request, '201_file', 'employee_201');
            if ($filePath) {
                $validated['201_file'] = $filePath;
            }

            // Copy applicant's resume
            $validated['resume'] = $applicant->resume;

            // Auto-fill from applicant
            $validated['first_name'] = $applicant->first_name;
            $validated['last_name']  = $applicant->last_name;
            $validated['email']      = $applicant->email;
            $validated['phone']      = $applicant->phone_number;
            $validated['password']   = Hash::make($validated['password']);

            // Create employee
            $employee = Employee::create($validated);

            // Update applicant stage/status
            $applicant->update(['stage' => 'hired']);

            // Send welcome email
            try {
                Mail::to($employee->email)->send(new EmployeeCreated($employee, $request->password));
            } catch (\Exception $e) {
                Log::warning('Failed to send hire email: ' . $e->getMessage());
            }

            return response()->json([
                'isSuccess' => true,
                'message'   => 'Applicant successfully hired and added to employee list.',
                'data'      => $employee
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error hiring applicant: ' . $e->getMessage());
            return response()->json([
                'isSuccess' => false,
                'message'   => 'Failed to hire applicant.'
            ], 500);
        }
    }





    /**
     * Update applicant status (Pending → Reviewed → Interview → etc.)
     */
    public function updateApplicantStatus(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'status' => 'required|in:Pending,Reviewed,Interview,Hired,Rejected',
            ]);

            $applicant = Applicant::findOrFail($id);
            $applicant->update(['status' => $validated['status']]);

            return response()->json([
                'isSuccess' => true,
                'message' => 'Applicant status updated successfully.',
                'data' => $applicant,
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating applicant status: ' . $e->getMessage());
            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to update applicant status.',
            ], 500);
        }
    }

    /**
     * Move applicant to another recruitment stage
     */
    public function moveStage(Request $request, $id)
    {
        try {
            // Validation
            $validated = $request->validate([
                'stage' => 'required|in:new_application,screening,phone_screening,assessment,technical_interview,final_interview,offer_extended,hired',
            ]);

            // Update applicant
            $applicant = Applicant::findOrFail($id);
            $applicant->update(['stage' => $validated['stage']]);

            return response()->json([
                'isSuccess' => true,
                'message'   => 'Applicant moved to the next stage successfully.',
                'data'      => $applicant,
            ]);
        } catch (ValidationException $e) {
            // Specific validation errors
            return response()->json([
                'isSuccess' => false,
                'message'   => 'Validation failed.',
                'errors'    => $e->errors(),
            ], 422);
        } catch (ModelNotFoundException $e) {
            // Applicant not found
            return response()->json([
                'isSuccess' => false,
                'message'   => 'Applicant not found.',
            ], 404);
        } catch (\Exception $e) {
            // Unexpected error
            Log::error('Error moving applicant stage: ' . $e->getMessage(), [
                'stack' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'isSuccess' => false,
                'message'   => 'An unexpected error occurred while moving the applicant stage.',
                'error'     => $e->getMessage(),
            ], 500);
        }
    }

    /**
     *  Archive (not delete) an applicant
     */
    public function archiveApplicant($id)
    {
        try {
            $applicant = Applicant::findOrFail($id);
            $applicant->update(['is_archived' => true]);

            return response()->json([
                'isSuccess' => true,
                'message' => 'Applicant archived successfully.',
            ]);
        } catch (\Exception $e) {
            Log::error('Error archiving applicant: ' . $e->getMessage());
            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to archive applicant.',
            ], 500);
        }
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
