<?php

namespace App\Http\Controllers;

use App\Models\Interview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class InterviewController extends Controller
{
    /**
     * Schedule a new interview for an applicant
     */
    public function scheduleInterview(Request $request, $applicantId)
    {
        try {
            $validated = $request->validate([
                'interviewer'   => 'required|string|max:150',
                'scheduled_at'  => 'required|date|after:now',
                'mode'          => 'nullable|in:online,in-person',
                'notes'         => 'nullable|string',
            ]);

            $validated['applicant_id'] = $applicantId;

            $interview = Interview::create($validated);

            return response()->json([
                'isSuccess' => true,
                'message'   => 'Interview scheduled successfully!',
                'data'      => $interview,
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error scheduling interview: ' . $e->getMessage());
            return response()->json([
                'isSuccess' => false,
                'message'   => 'Failed to schedule interview.',
            ], 500);
        }
    }
    public function getAllInterviews()
    {
        try {
            $interviews = Interview::with('applicant') // optional if you have a relation
                ->orderBy('scheduled_at', 'desc')
                ->get();

            return response()->json([
                'isSuccess' => true,
                'message'   => 'All interviews retrieved successfully.',
                'data'      => $interviews,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching all interviews: ' . $e->getMessage());
            return response()->json([
                'isSuccess' => false,
                'message'   => 'Failed to retrieve interviews.',
            ], 500);
        }
    }


    /**
     * Get all interviews for a specific applicant
     */
    public function getInterviews($applicantId)
    {
        try {
            $interviews = Interview::where('applicant_id', $applicantId)
                ->orderBy('scheduled_at', 'desc')
                ->get();

            return response()->json([
                'isSuccess' => true,
                'message'   => 'Interviews retrieved successfully.',
                'data'      => $interviews,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching interviews: ' . $e->getMessage());
            return response()->json([
                'isSuccess' => false,
                'message'   => 'Failed to retrieve interviews.',
            ], 500);
        }
    }

    /**
     * Update interview details (reschedule, change mode, notes, or status)
     */
    public function updateInterview(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'interviewer'   => 'nullable|string|max:150',
                'scheduled_at'  => 'nullable|date|after:now',
                'mode'          => 'nullable|in:online,in-person',
                'notes'         => 'nullable|string',
                'status'        => 'nullable|in:scheduled,completed,cancelled',
            ]);

            $interview = Interview::findOrFail($id);
            $interview->update($validated);

            return response()->json([
                'isSuccess' => true,
                'message'   => 'Interview updated successfully.',
                'data'      => $interview,
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating interview: ' . $e->getMessage());
            return response()->json([
                'isSuccess' => false,
                'message'   => 'Failed to update interview.',
            ], 500);
        }
    }

    /**
     * Delete (or cancel) an interview
     */
    public function cancelInterview($id)
    {
        try {
            $interview = Interview::findOrFail($id);
            $interview->update(['status' => 'cancelled']);

            return response()->json([
                'isSuccess' => true,
                'message'   => 'Interview cancelled successfully.',
            ]);
        } catch (\Exception $e) {
            Log::error('Error cancelling interview: ' . $e->getMessage());
            return response()->json([
                'isSuccess' => false,
                'message'   => 'Failed to cancel interview.',
            ], 500);
        }
    }
}
