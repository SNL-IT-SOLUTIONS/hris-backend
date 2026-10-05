<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\OvertimeRequest;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class OvertimeRequestController extends Controller
{
    /**
     * Create overtime request
     */
    public function createOvertimeRequest(Request $request)
    {
        try {

            // ---------------------------------------------------------
            // Get authenticated employee
            // ---------------------------------------------------------
            $employee = $request->user();

            if (!$employee) {
                return response()->json([
                    'isSuccess' => false,
                    'message'   => 'Unauthenticated.',
                ], 401);
            }

            // ---------------------------------------------------------
            // Check employee status
            // ---------------------------------------------------------
            if ((int) $employee->is_archived === 1) {
                return response()->json([
                    'isSuccess' => false,
                    'message'   => 'Your employee account has been archived.',
                ], 403);
            }

            if (isset($employee->is_active) && (int) $employee->is_active !== 1) {
                return response()->json([
                    'isSuccess' => false,
                    'message'   => 'Your employee account is inactive.',
                ], 403);
            }

            // ---------------------------------------------------------
            // Validate request
            // ---------------------------------------------------------
            $validated = $request->validate([
                'overtime_date' => 'required|date',
                'start_time'    => 'required|date_format:H:i',
                'end_time'      => 'required|date_format:H:i',
                'reason'        => 'nullable|string|max:500',
            ]);

            // ---------------------------------------------------------
            // Calculate overtime hours
            // ---------------------------------------------------------
            $startTime = \Carbon\Carbon::createFromFormat(
                'H:i',
                $validated['start_time']
            );

            $endTime = \Carbon\Carbon::createFromFormat(
                'H:i',
                $validated['end_time']
            );

            if ($endTime->lessThanOrEqualTo($startTime)) {
                return response()->json([
                    'isSuccess' => false,
                    'message'   => 'The overtime end time must be later than the start time.',
                ], 422);
            }

            $totalMinutes = $startTime->diffInMinutes($endTime);
            $totalHours = round($totalMinutes / 60, 2);

            if ($totalHours <= 0) {
                return response()->json([
                    'isSuccess' => false,
                    'message'   => 'Overtime hours must be greater than zero.',
                ], 422);
            }

            // ---------------------------------------------------------
            // Prevent duplicate / overlapping OT requests
            // ---------------------------------------------------------
            $existingRequest = OvertimeRequest::where(
                'employee_id',
                $employee->id
            )
                ->where('overtime_date', $validated['overtime_date'])
                ->where('is_archived', 0)
                ->whereIn('status', ['Pending', 'Approved'])
                ->where(function ($query) use ($validated) {

                    $query->where(
                        'start_time',
                        '<',
                        $validated['end_time']
                    )
                        ->where(
                            'end_time',
                            '>',
                            $validated['start_time']
                        );
                })
                ->first();

            if ($existingRequest) {
                return response()->json([
                    'isSuccess' => false,
                    'message'   => 'You already have a pending or approved overtime request that overlaps with this time.',
                    'existing_request' => $existingRequest,
                ], 422);
            }

            // ---------------------------------------------------------
            // Create overtime request
            // ---------------------------------------------------------
            $overtimeRequest = OvertimeRequest::create([
                'employee_id'      => $employee->id,
                'overtime_date'    => $validated['overtime_date'],
                'start_time'       => $validated['start_time'],
                'end_time'         => $validated['end_time'],
                'total_hours'      => $totalHours,
                'reason'           => $validated['reason'] ?? null,
                'status'           => 'Pending',
                'approved_by'      => null,
                'approved_at'      => null,
                'rejection_reason' => null,
                'is_archived'      => 0,
            ]);

            // ---------------------------------------------------------
            // Send email notification
            // ---------------------------------------------------------
            try {

                // Get employee name
                $employeeName = trim(
                    ($employee->first_name ?? '') . ' ' .
                        ($employee->last_name ?? '')
                );

                // Fallback if first_name / last_name are unavailable
                if (empty($employeeName)) {
                    $employeeName = $employee->name
                        ?? "Employee #{$employee->id}";
                }

                $reason = $validated['reason'] ?? 'No reason provided.';

                // Format date
                $overtimeDate = \Carbon\Carbon::parse(
                    $validated['overtime_date']
                )->format('F d, Y');

                // Format time
                $startTimeFormatted = \Carbon\Carbon::createFromFormat(
                    'H:i',
                    $validated['start_time']
                )->format('h:i A');

                $endTimeFormatted = \Carbon\Carbon::createFromFormat(
                    'H:i',
                    $validated['end_time']
                )->format('h:i A');

                // Email subject
                $subject = "New Overtime Request - {$employeeName}";

                // Email body
                $emailBody = "
Hello HR Team,

A new overtime request has been submitted through the HRIS.

EMPLOYEE DETAILS
--------------------------------
Employee Name: {$employeeName}
Employee ID: {$employee->id}

OVERTIME DETAILS
--------------------------------
Overtime Date: {$overtimeDate}
Start Time: {$startTimeFormatted}
End Time: {$endTimeFormatted}
Total Overtime Hours: {$totalHours}
Reason: {$reason}
Status: Pending

Please log in to the HRIS to review this overtime request.

This is an automated notification from the SNL Virtual Partner HRIS.
";

                \Illuminate\Support\Facades\Mail::raw(
                    $emailBody,
                    function ($mail) use ($subject) {

                        $mail->to('hello@snlvirtualpartner.com')
                            ->subject($subject);
                    }
                );

                \Illuminate\Support\Facades\Log::info(
                    "Overtime notification email sent for employee ID {$employee->id}"
                );
            } catch (\Exception $mailException) {

                // -----------------------------------------------------
                // Email failure should NOT cancel the OT request
                // -----------------------------------------------------
                \Illuminate\Support\Facades\Log::error(
                    'Overtime notification email failed: ' .
                        $mailException->getMessage()
                );
            }

            // ---------------------------------------------------------
            // Log overtime request
            // ---------------------------------------------------------
            \Illuminate\Support\Facades\Log::info(
                "Overtime request created for employee ID {$employee->id} " .
                    "({$totalHours} hour(s) on {$validated['overtime_date']})"
            );

            // ---------------------------------------------------------
            // Return response
            // ---------------------------------------------------------
            return response()->json([
                'isSuccess' => true,
                'message'   => 'Overtime request submitted successfully.',
                'data'      => $overtimeRequest,
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'isSuccess' => false,
                'message'   => 'The given data was invalid.',
                'errors'    => $e->errors(),
            ], 422);
        } catch (\Exception $e) {

            \Illuminate\Support\Facades\Log::error(
                'Error creating overtime request: ' . $e->getMessage()
            );

            return response()->json([
                'isSuccess' => false,
                'message'   => 'Failed to submit overtime request.',
                'error'     => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Get authenticated employee's overtime requests
     */
    public function getMyOvertimeRequests(Request $request)
    {
        try {

            // ---------------------------------------------------------
            // Get authenticated employee
            // ---------------------------------------------------------

            $employee = $request->user();

            if (!$employee) {
                return response()->json([
                    'isSuccess' => false,
                    'message'   => 'Unauthenticated.',
                ], 401);
            }

            // ---------------------------------------------------------
            // Pagination
            // ---------------------------------------------------------

            $perPage = $request->input('per_page', 10);

            $perPage = min(max((int) $perPage, 1), 100);

            // ---------------------------------------------------------
            // Get employee OT requests
            // ---------------------------------------------------------

            $overtimeRequests = OvertimeRequest::where(
                'employee_id',
                $employee->id
            )
                ->where('is_archived', 0)
                ->orderBy('overtime_date', 'desc')
                ->orderBy('start_time', 'desc')
                ->paginate($perPage);

            return response()->json([
                'isSuccess' => true,
                'message'   => 'Overtime requests retrieved successfully.',
                'data'      => $overtimeRequests->items(),
                'pagination' => [
                    'current_page' => $overtimeRequests->currentPage(),
                    'perPage'      => $overtimeRequests->perPage(),
                    'total'        => $overtimeRequests->total(),
                    'last_page'    => $overtimeRequests->lastPage(),
                ],
            ], 200);
        } catch (\Exception $e) {

            Log::error(
                'Error retrieving employee overtime requests: ' .
                    $e->getMessage()
            );

            return response()->json([
                'isSuccess' => false,
                'message'   => 'Failed to retrieve overtime requests.',
                'error'     => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Get all overtime requests
     * Admin use
     */
    public function getAllOvertimeRequests(Request $request)
    {
        try {

            // ---------------------------------------------------------
            // Get authenticated employee
            // ---------------------------------------------------------

            $employee = $request->user();

            if (!$employee) {
                return response()->json([
                    'isSuccess' => false,
                    'message'   => 'Unauthenticated.',
                ], 401);
            }

            // ---------------------------------------------------------
            // Pagination
            // ---------------------------------------------------------

            $perPage = $request->input('per_page', 10);

            $perPage = min(max((int) $perPage, 1), 100);

            // ---------------------------------------------------------
            // Optional status filter
            // ---------------------------------------------------------

            $query = OvertimeRequest::with([
                'employee',
                'approver'
            ])
                ->where('is_archived', 0);

            if ($request->filled('status')) {
                $query->where(
                    'status',
                    $request->input('status')
                );
            }

            // ---------------------------------------------------------
            // Optional employee filter
            // ---------------------------------------------------------

            if ($request->filled('employee_id')) {
                $query->where(
                    'employee_id',
                    $request->input('employee_id')
                );
            }

            // ---------------------------------------------------------
            // Get records
            // ---------------------------------------------------------

            $overtimeRequests = $query
                ->orderBy('overtime_date', 'desc')
                ->orderBy('start_time', 'desc')
                ->paginate($perPage);

            return response()->json([
                'isSuccess' => true,
                'message'   => 'Overtime requests retrieved successfully.',
                'data'      => $overtimeRequests->items(),
                'pagination' => [
                    'current_page' => $overtimeRequests->currentPage(),
                    'perPage'      => $overtimeRequests->perPage(),
                    'total'        => $overtimeRequests->total(),
                    'last_page'    => $overtimeRequests->lastPage(),
                ],
            ], 200);
        } catch (\Exception $e) {

            Log::error(
                'Error retrieving all overtime requests: ' .
                    $e->getMessage()
            );

            return response()->json([
                'isSuccess' => false,
                'message'   => 'Failed to retrieve overtime requests.',
                'error'     => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Approve overtime request
     */
    public function approveOvertimeRequest(Request $request, $id)
    {
        try {

            // ---------------------------------------------------------
            // Get authenticated approver
            // ---------------------------------------------------------

            $approver = $request->user();

            if (!$approver) {
                return response()->json([
                    'isSuccess' => false,
                    'message'   => 'Unauthenticated.',
                ], 401);
            }

            // ---------------------------------------------------------
            // Check approver status
            // ---------------------------------------------------------

            if ((int) $approver->is_archived === 1) {
                return response()->json([
                    'isSuccess' => false,
                    'message'   => 'Your employee account has been archived.',
                ], 403);
            }

            // ---------------------------------------------------------
            // Find overtime request
            // ---------------------------------------------------------

            $overtimeRequest = OvertimeRequest::where('id', $id)
                ->where('is_archived', 0)
                ->first();

            if (!$overtimeRequest) {
                return response()->json([
                    'isSuccess' => false,
                    'message'   => 'Overtime request not found.',
                ], 404);
            }

            // ---------------------------------------------------------
            // Only Pending can be approved
            // ---------------------------------------------------------

            if ($overtimeRequest->status !== 'Pending') {
                return response()->json([
                    'isSuccess' => false,
                    'message'   => 'Only pending overtime requests can be approved.',
                    'current_status' => $overtimeRequest->status,
                ], 422);
            }

            // ---------------------------------------------------------
            // Prevent employee from approving own request
            // ---------------------------------------------------------

            if ((int) $overtimeRequest->employee_id === (int) $approver->id) {
                return response()->json([
                    'isSuccess' => false,
                    'message'   => 'You cannot approve your own overtime request.',
                ], 403);
            }

            // ---------------------------------------------------------
            // Approve request
            // ---------------------------------------------------------

            $overtimeRequest->update([
                'status'      => 'Approved',
                'approved_by' => $approver->id,
                'approved_at' => now(),
            ]);

            Log::info(
                "Overtime request ID {$overtimeRequest->id} approved by employee ID {$approver->id}"
            );

            return response()->json([
                'isSuccess' => true,
                'message'   => 'Overtime request approved successfully.',
                'data'      => $overtimeRequest->fresh([
                    'employee',
                    'approver'
                ]),
            ], 200);
        } catch (\Exception $e) {

            Log::error(
                'Error approving overtime request: ' .
                    $e->getMessage()
            );

            return response()->json([
                'isSuccess' => false,
                'message'   => 'Failed to approve overtime request.',
                'error'     => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Reject overtime request
     */
    public function rejectOvertimeRequest(Request $request, $id)
    {
        try {

            // ---------------------------------------------------------
            // Get authenticated approver
            // ---------------------------------------------------------

            $approver = $request->user();

            if (!$approver) {
                return response()->json([
                    'isSuccess' => false,
                    'message'   => 'Unauthenticated.',
                ], 401);
            }

            // ---------------------------------------------------------
            // Check approver status
            // ---------------------------------------------------------

            if ((int) $approver->is_archived === 1) {
                return response()->json([
                    'isSuccess' => false,
                    'message'   => 'Your employee account has been archived.',
                ], 403);
            }

            // ---------------------------------------------------------
            // Validate rejection reason
            // ---------------------------------------------------------

            $validated = $request->validate([
                'rejection_reason' => 'required|string|max:500',
            ]);

            // ---------------------------------------------------------
            // Find overtime request
            // ---------------------------------------------------------

            $overtimeRequest = OvertimeRequest::where('id', $id)
                ->where('is_archived', 0)
                ->first();

            if (!$overtimeRequest) {
                return response()->json([
                    'isSuccess' => false,
                    'message'   => 'Overtime request not found.',
                ], 404);
            }

            // ---------------------------------------------------------
            // Only Pending can be rejected
            // ---------------------------------------------------------

            if ($overtimeRequest->status !== 'Pending') {
                return response()->json([
                    'isSuccess' => false,
                    'message'   => 'Only pending overtime requests can be rejected.',
                    'current_status' => $overtimeRequest->status,
                ], 422);
            }

            // ---------------------------------------------------------
            // Prevent employee from rejecting own request
            // ---------------------------------------------------------

            if ((int) $overtimeRequest->employee_id === (int) $approver->id) {
                return response()->json([
                    'isSuccess' => false,
                    'message'   => 'You cannot reject your own overtime request.',
                ], 403);
            }

            // ---------------------------------------------------------
            // Reject request
            // ---------------------------------------------------------

            $overtimeRequest->update([
                'status'           => 'Rejected',
                'approved_by'      => $approver->id,
                'approved_at'      => null,
                'rejection_reason' => $validated['rejection_reason'],
            ]);

            Log::info(
                "Overtime request ID {$overtimeRequest->id} rejected by employee ID {$approver->id}"
            );

            return response()->json([
                'isSuccess' => true,
                'message'   => 'Overtime request rejected successfully.',
                'data'      => $overtimeRequest->fresh([
                    'employee',
                    'approver'
                ]),
            ], 200);
        } catch (ValidationException $e) {

            return response()->json([
                'isSuccess' => false,
                'message'   => 'The given data was invalid.',
                'errors'    => $e->errors(),
            ], 422);
        } catch (\Exception $e) {

            Log::error(
                'Error rejecting overtime request: ' .
                    $e->getMessage()
            );

            return response()->json([
                'isSuccess' => false,
                'message'   => 'Failed to reject overtime request.',
                'error'     => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Cancel own pending overtime request
     */
    public function cancelOvertimeRequest(Request $request, $id)
    {
        try {

            // ---------------------------------------------------------
            // Get authenticated employee
            // ---------------------------------------------------------

            $employee = $request->user();

            if (!$employee) {
                return response()->json([
                    'isSuccess' => false,
                    'message'   => 'Unauthenticated.',
                ], 401);
            }

            // ---------------------------------------------------------
            // Find own overtime request
            // ---------------------------------------------------------

            $overtimeRequest = OvertimeRequest::where('id', $id)
                ->where('employee_id', $employee->id)
                ->where('is_archived', 0)
                ->first();

            if (!$overtimeRequest) {
                return response()->json([
                    'isSuccess' => false,
                    'message'   => 'Overtime request not found.',
                ], 404);
            }

            // ---------------------------------------------------------
            // Only Pending can be cancelled
            // ---------------------------------------------------------

            if ($overtimeRequest->status !== 'Pending') {
                return response()->json([
                    'isSuccess' => false,
                    'message'   => 'Only pending overtime requests can be cancelled.',
                    'current_status' => $overtimeRequest->status,
                ], 422);
            }

            // ---------------------------------------------------------
            // Cancel request
            // ---------------------------------------------------------

            $overtimeRequest->update([
                'status' => 'Cancelled',
            ]);

            Log::info(
                "Overtime request ID {$overtimeRequest->id} cancelled by employee ID {$employee->id}"
            );

            return response()->json([
                'isSuccess' => true,
                'message'   => 'Overtime request cancelled successfully.',
                'data'      => $overtimeRequest->fresh(),
            ], 200);
        } catch (\Exception $e) {

            Log::error(
                'Error cancelling overtime request: ' .
                    $e->getMessage()
            );

            return response()->json([
                'isSuccess' => false,
                'message'   => 'Failed to cancel overtime request.',
                'error'     => $e->getMessage(),
            ], 500);
        }
    }
}
