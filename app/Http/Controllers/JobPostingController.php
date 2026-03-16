<?php

namespace App\Http\Controllers;

use App\Models\JobPosting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class JobPostingController extends Controller
{
    // Create a new job posting
    public function createJobPosting(Request $request)
    {
        try {
            $validated = $request->validate([
                'title'          => 'required|string|max:255',
                'department_id'  => 'required|exists:departments,id',
                'location'       => 'nullable|string|max:255',
                'work_type'      => 'sometimes|string|max:100',
                'employment_type' => 'sometimes|string|max:100',
                'salary_range'   => 'nullable|string|max:100',
                'description'    => 'nullable|string',
                'status'         => 'in:draft,active,closed',
                'posted_date'    => 'nullable|date',
                'deadline_date'  => 'nullable|date|after_or_equal:posted_date',
            ]);

            $validated['is_archived'] = false;

            $job = JobPosting::create($validated);

            return response()->json([
                'isSuccess' => true,
                'message' => 'Job posting created successfully.',
                'data' => $job,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to create job posting.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    //  Get all job postings
    public function getJobPostings(Request $request)
    {
        try {
            $query = JobPosting::with('department')
                ->where('is_archived', false)
                ->whereIn('status', ['active', 'draft', 'open']);

            // Search
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'LIKE', "%$search%")
                        ->orWhere('location', 'LIKE', "%$search%")
                        ->orWhere('salary_range', 'LIKE', "%$search%")
                        ->orWhereHas('department', function ($dq) use ($search) {
                            $dq->where('department_name', 'LIKE', "%$search%");
                        });
                });
            }

            //  Filter by department
            if ($request->filled('department_id')) {
                $query->where('department_id', $request->department_id);
            }

            //  Optional: filter by status if provided (overrides default active+draft)
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            //  Pagination
            $perPage = $request->input('per_page', 10);
            $jobs = $query->orderBy('created_at', 'desc')->paginate($perPage);

            if ($jobs->isEmpty()) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'No job postings found.',
                ], 404);
            }

            return response()->json([
                'isSuccess' => true,
                'message' => 'Job postings retrieved successfully.',
                'job_postings' => $jobs->items(),
                'pagination' => [
                    'current_page' => $jobs->currentPage(),
                    'per_page' => $jobs->perPage(),
                    'total' => $jobs->total(),
                    'last_page' => $jobs->lastPage(),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching job postings: ' . $e->getMessage());
            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to retrieve job postings.',
            ], 500);
        }
    }




    //  Update job posting
    public function updateJobPosting(Request $request, $id)
    {
        try {
            $job = JobPosting::find($id);

            if (!$job || $job->is_archived) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'Job posting not found.',
                ], 404);
            }

            $validated = $request->validate([
                'title'          => 'sometimes|string|max:255',
                'department_id'  => 'sometimes|exists:departments,id',
                'work_type'      => 'sometimes|string|max:100',
                'employment_type' => 'sometimes|string|max:100',
                'location'       => 'nullable|string|max:255',
                'salary_range'   => 'nullable|string|max:100',
                'description'    => 'nullable|string',
                'status'         => 'in:draft,active,open,closed',
                'posted_date'    => 'nullable|date',
                'deadline_date'  => 'nullable|date|after_or_equal:posted_date',
            ]);

            $job->update($validated);

            return response()->json([
                'isSuccess' => true,
                'message' => 'Job posting updated successfully.',
                'data' => $job,
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating job posting: ' . $e->getMessage());
            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to update job posting.',
            ], 500);
        }
    }

    // Archive (not delete) job posting
    public function archiveJobPosting($id)
    {
        try {
            $job = JobPosting::find($id);

            if (!$job || $job->is_archived) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'Job posting not found.',
                ], 404);
            }

            $job->update(['is_archived' => true]);

            return response()->json([
                'isSuccess' => true,
                'message' => 'Job posting archived successfully.',
            ]);
        } catch (\Exception $e) {
            Log::error('Error archiving job posting: ' . $e->getMessage());
            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to archive job posting.',
            ], 500);
        }
    }
}
