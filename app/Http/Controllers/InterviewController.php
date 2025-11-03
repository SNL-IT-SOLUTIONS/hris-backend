<?php

namespace App\Http\Controllers;

use App\Models\Interview;
use App\Models\Applicant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Mail\InterviewScheduledMail;
use Illuminate\Support\Facades\Mail;


class InterviewController extends Controller
{
    /**
     * Schedule a new interview for an applicant
     */
    public function scheduleInterview(Request $request, $applicantId)
    {
        try {
            $validated = $request->validate([
                'interviewer_id' => 'required|exists:employees,id',
                'scheduled_at'   => 'required|date|after:now',
                'mode'           => 'nullable|in:online,in-person',
                'notes'          => 'nullable|string',
                'location_link'  => 'nullable|string',
            ]);

            $applicant = Applicant::findOrFail($applicantId);

            $validated['applicant_id'] = $applicantId;
            $validated['stage'] = $applicant->stage;
            $validated['position'] = optional($applicant->jobPosting)->title ?? 'N/A';
            $validated['status'] = 'scheduled';

            $interview = Interview::create($validated);

            // 📧 Send email notification
            if (!empty($applicant->email)) {
                Mail::to($applicant->email)->send(new InterviewScheduledMail($applicant, $interview));
            }

            return response()->json([
                'isSuccess' => true,
                'message'   => 'Interview scheduled and email notification sent!',
                'data'      => $interview->load(['applicant', 'interviewer']),
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error scheduling interview: ' . $e->getMessage());
            return response()->json([
                'isSuccess' => false,
                'message'   => 'Failed to schedule interview.',
                'error'     => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Retrieve all interviews with applicant and interviewer details
     */
    public function getAllInterviews()
    {
        try {
            $interviews = Interview::with(['applicant', 'interviewer'])
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
            $interviews = Interview::with('interviewer')
                ->where('applicant_id', $applicantId)
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
                'interviewer_id' => 'nullable|exists:employees,id',
                'scheduled_at'   => 'nullable|date|after:now',
                'mode'           => 'nullable|in:online,in-person',
                'notes'          => 'nullable|string',
                'status'         => 'nullable|in:scheduled,completed,cancelled',
                'location_link'  => 'nullable|string',
            ]);

            $interview = Interview::findOrFail($id);
            $interview->update($validated);

            return response()->json([
                'isSuccess' => true,
                'message'   => 'Interview updated successfully.',
                'data'      => $interview->load(['applicant', 'interviewer']),
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating interview: ' . $e->getMessage());
            return response()->json([
                'isSuccess' => false,
                'message'   => 'Failed to update interview.',
            ], 500);
        }
    }


    public function submitFeedback(Request $request, $interviewId)
    {
        try {
            $validated = $request->validate([
                'overall_rating'       => 'required|in:1,2,3,4,5',
                'technical_skills'     => 'required|in:1,2,3,4,5',
                'communication'        => 'required|in:1,2,3,4,5',
                'cultural_fit'         => 'required|in:1,2,3,4,5',
                'problem_solving'      => 'required|in:1,2,3,4,5',
                'experience_level'     => 'required|in:1,2,3,4,5',
                'recommendation'       => 'required|in:hire,hold,reject',
                'key_strengths'        => 'nullable|string',
                'areas_for_improvement' => 'nullable|string',
                'detailed_notes'       => 'nullable|string',
            ]);

            $interview = Interview::findOrFail($interviewId);

            // Mark interview as completed when feedback is submitted
            $interview->update(['status' => 'completed']);

            $feedback = $interview->feedback()->create($validated);

            return response()->json([
                'isSuccess' => true,
                'message'   => 'Interview feedback submitted successfully.',
                'data'      => $feedback,
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error submitting feedback: ' . $e->getMessage());
            return response()->json([
                'isSuccess' => false,
                'message'   => 'Failed to submit feedback.',
            ], 500);
        }
    }



    public function noshowInterview($id)
    {
        try {
            $interview = Interview::findOrFail($id);
            $interview->update(['status' => 'no show']);

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

    /**
     * Cancel an interview (soft cancel via status)
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
