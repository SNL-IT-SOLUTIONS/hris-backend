<?php

namespace App\Http\Controllers;

use App\Models\Applicant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ApplicantController extends Controller
{
    /**
     * ✅ Create a new applicant (job application submission)
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
                'linkedin_profile'     => 'nullable|url|max:255',
                'portfolio_website'    => 'nullable|url|max:255',
                'salary_expectations'  => 'nullable|string|max:100',
                'available_start_date' => 'nullable|string|max:100',
                'experience_years'     => 'nullable|integer|min:0',
            ]);

            // ✅ Upload resume using your helper
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
        } catch (\Exception $e) {
            Log::error('Error creating applicant: ' . $e->getMessage());

            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to submit application.',
            ], 500);
        }
    }


    /**
     * ✅ Get all applicants
     */
    public function getApplicants(Request $request)
    {
        try {
            $applicants = Applicant::with('jobPosting')
                ->where('is_archived', false)
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
     * ✅ Get a single applicant by ID
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
     * ✅ Update applicant status (Pending → Reviewed → Interview → etc.)
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
     * ✅ Move applicant to another recruitment stage
     */
    public function moveStage(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'stage' => 'required|in:new_application,screening,phone_screening,assessment,technical_interview,final_interview,offer_extended,hired',
            ]);

            $applicant = Applicant::findOrFail($id);
            $applicant->update(['stage' => $validated['stage']]);

            return response()->json([
                'isSuccess' => true,
                'message' => 'Applicant moved to the next stage successfully.',
                'data' => $applicant,
            ]);
        } catch (\Exception $e) {
            Log::error('Error moving applicant stage: ' . $e->getMessage());
            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to move applicant stage.',
            ], 500);
        }
    }

    /**
     * ✅ Archive (not delete) an applicant
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
