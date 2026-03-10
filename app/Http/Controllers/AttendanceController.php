<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use App\Models\Leave;
use Illuminate\Support\Facades\Log;
use App\Models\LeaveType;
use App\Models\Employee;
use App\Models\EmployeeFace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use App\Models\EmployeeLeaveBalance;
use Exception;
use Illuminate\Support\Facades\Mail;
use App\Mail\ClockInNotification;
use App\Mail\EndOfDayReportMail;
use App\Mail\AdjustmentRequestMail;
use App\Models\AttendanceAdjustment;


class AttendanceController extends Controller
{

    public function registerFace(Request $request)
    {
        try {
            $user = auth()->user();

            $request->validate([
                'face_image' => 'required|file|image|mimes:jpeg,jpg,png|max:5120',
            ]);

            // Save the uploaded file
            $file = $request->file('face_image');
            $path = $this->saveFileToPublic($file, 'face_' . $user->id . '_' . time());

            // Check if the employee already has a face record
            $faceRecord = EmployeeFace::where('employee_id', $user->id)->first();

            if ($faceRecord) {
                // Update existing face record
                $faceRecord->update(['face_image_path' => $path]);
            } else {
                // Create new face record
                $faceRecord = EmployeeFace::create([
                    'employee_id' => $user->id,
                    'face_image_path' => $path,
                ]);
            }

            // Update the employees table with the face_id
            $user->update(['face_id' => $faceRecord->id]);

            return response()->json([
                'message' => 'Face successfully registered!',
                'employee' => [
                    'id' => $user->id,
                    'name' => "{$user->first_name} {$user->last_name}",
                    'face_image_url' => asset($faceRecord->face_image_path),
                    'face_id' => $faceRecord->id,
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to register face.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Display a listing of all attendance records.
     */
    public function getAllAttendances()
    {
        $attendances = Attendance::with([
            'employee:id,profile_picture,first_name,last_name,email,department_id,position_id',
            'approvedAdjustment'
        ])
            ->orderBy('created_at', 'desc')
            ->get();

        $attendances->transform(function ($attendance) {

            // If approved adjustment exists, override times
            if ($attendance->approvedAdjustment) {
                $attendance->clock_in  = $attendance->approvedAdjustment->requested_clock_in;
                $attendance->clock_out = $attendance->approvedAdjustment->requested_clock_out;
            }

            // Convert attendance images to full URL
            $attendance->clock_in_image = $attendance->clock_in_image
                ? asset($attendance->clock_in_image)
                : null;

            $attendance->clock_out_image = $attendance->clock_out_image
                ? asset($attendance->clock_out_image)
                : null;

            // Convert employee profile picture to full URL
            if ($attendance->employee && $attendance->employee->profile_picture) {
                $attendance->employee->profile_picture = asset($attendance->employee->profile_picture);
            }

            return $attendance;
        });

        return response()->json([
            'isSuccess' => true,
            'data' => $attendances,
        ]);
    }



    public function getAllLeaves()
    {
        $leaves = Leave::with([
            'employee:id,first_name,last_name,email,profile_picture',
            'leaveType:id,name'
        ])
            ->orderBy('created_at', 'desc')
            ->get();

        $leaves->transform(function ($leave) {

            // Convert profile picture to full URL
            if ($leave->employee && $leave->employee->profile_picture) {
                $leave->employee->profile_picture = asset($leave->employee->profile_picture);
            }

            return $leave;
        });

        return response()->json([
            'isSuccess' => true,
            'data' => $leaves
        ]);
    }


    public function confirmleave(Request $request, $id)
    {
        $leave = Leave::find($id);

        if (!$leave) {
            return response()->json(['isSuccess' => false, 'message' => 'Leave request not found.'], 404);
        }

        // Validate status input
        $validated = $request->validate([
            'status' => 'required|in:Approved,Rejected',
        ]);

        $leave->status = $validated['status'];
        $leave->save();

        return response()->json([
            'isSuccess' => true,
            'message'   => 'Leave request ' . strtolower($validated['status']) . ' successfully.',
            'leave'     => $leave,
        ]);
    }



    /**
     * Clock In
     */

    public function clockIn(Request $request)
    {
        try {
            $user = auth()->user();

            if (!$user) {
                return response()->json([
                    'message' => 'Unauthorized. Please log in.'
                ], 401);
            }

            $validator = Validator::make($request->all(), [
                'face_image' => 'nullable|file|image|mimes:jpeg,png,jpg|max:5120',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Invalid image.',
                    'errors' => $validator->errors()
                ], 422);
            }

            $employee = $user;

            /*
        |--------------------------------------------------------------------------
        |  Block if there is still an OPEN attendance
        |--------------------------------------------------------------------------
        */
            $openAttendance = Attendance::where('employee_id', $employee->id)
                ->whereNull('clock_out')
                ->latest('clock_in')
                ->first();

            if ($openAttendance) {
                return response()->json([
                    'message' => 'You must clock out before clocking in again.'
                ], 400);
            }

            /*
        |--------------------------------------------------------------------------
        |  Prevent accidental re-clock-in (cooldown protection)
        |--------------------------------------------------------------------------
        | This prevents:
        | - Misclick after clocking out
        | - Creating multiple attendances in a short time
        |
        | You can adjust this cooldown to 6, 8, or 10 hours
        |--------------------------------------------------------------------------
        */
            $lastAttendance = Attendance::where('employee_id', $employee->id)
                ->latest('clock_in')
                ->first();

            if ($lastAttendance && $lastAttendance->clock_out) {

                $hoursSinceClockOut = Carbon::parse($lastAttendance->clock_out)
                    ->diffInHours(now());

                $cooldownHours = 1; // adjust if needed

                if ($hoursSinceClockOut < $cooldownHours) {
                    return response()->json([
                        'message' => 'You recently clocked out. Please wait before clocking in again.'
                    ], 400);
                }
            }

            /*
        |--------------------------------------------------------------------------
        |  Save image (if any)
        |--------------------------------------------------------------------------
        */
            $imagePath = null;

            if ($request->hasFile('face_image')) {
                $uploadedFile = $request->file('face_image');
                $imagePath = $this->saveFileToPublic(
                    $uploadedFile,
                    'attendance_in_' . $employee->id . '_' . time()
                );
            }

            /*
        |--------------------------------------------------------------------------
        Create attendance
        |--------------------------------------------------------------------------
        */
            $attendance = Attendance::create([
                'employee_id'    => $employee->id,
                'clock_in'       => now(),
                'status'         => 'Present',
                'method'         => $request->hasFile('face_image')
                    ? 'Facial Recognition'
                    : 'Manual',
                'clock_in_image' => $imagePath,
            ]);

            $dateNow = now()->format('Y-m-d H:i:s');

            Mail::to('gimme473@gmail.com')->send(
                new ClockInNotification($employee, $dateNow)
            );

            return response()->json([
                'message'    => 'Clocked in successfully!',
                'employee'   => $employee->first_name . ' ' . $employee->last_name,
                'attendance' => $attendance,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to clock in.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function clockOut(Request $request)
    {
        try {
            $user = auth()->user();

            if (!$user) {
                return response()->json([
                    'message' => 'Unauthorized. Please log in.'
                ], 401);
            }

            $validator = Validator::make($request->all(), [
                'report_today' => 'nullable|string',
                'face_image'   => 'nullable|file|image|mimes:jpeg,png,jpg|max:5120',
                'cc_emails'    => 'nullable|string',
                'subject'      => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Invalid input.',
                    'errors'  => $validator->errors()
                ], 422);
            }

            $employee = $user;

            // Find latest open attendance
            $attendance = Attendance::where('employee_id', $employee->id)
                ->whereNull('clock_out')
                ->orderBy('clock_in', 'desc')
                ->first();

            if (!$attendance) {
                return response()->json([
                    'message' => 'You have not clocked in yet.'
                ], 400);
            }

            if ($attendance->clock_out !== null) {
                return response()->json([
                    'message' => 'Already clocked out.'
                ], 400);
            }

            // Save clock-out image
            $imagePath = null;
            if ($request->hasFile('face_image')) {
                $uploadedFile = $request->file('face_image');
                $imagePath = $this->saveFileToPublic(
                    $uploadedFile,
                    'attendance_out_' . $employee->id . '_' . time()
                );
            }

            // Update attendance record
            $attendance->clock_out = Carbon::now();
            $attendance->clock_out_image = $imagePath;
            $attendance->report_today = $request->report_today;
            $attendance->method = $request->hasFile('face_image') ? 'Facial Recognition' : 'Manual';

            // Calculate hours worked
            if (method_exists($attendance, 'calculateHoursWorked')) {
                $attendance->calculateHoursWorked();
            } else {
                if ($attendance->clock_in && $attendance->clock_out) {
                    $attendance->hours_worked = Carbon::parse($attendance->clock_in)
                        ->diffInMinutes(Carbon::parse($attendance->clock_out)) / 60;
                }
            }

            $attendance->save();

            // Prepare email
            $employeeName = $employee->first_name . ' ' . $employee->last_name;
            $subject = $request->subject ?: "End of Day Report - {$employeeName} - " . now()->format('m/d/y');

            $reportBody = $attendance->report_today;

            // Process CC emails if provided
            $ccEmails = [];
            if ($request->cc_emails) {
                $ccEmails = array_map('trim', explode(',', $request->cc_emails));
            }

            // Send EOD email
            Mail::to('hello@snlvirtualpartner.com')
                ->cc($ccEmails)
                ->send(new EndOfDayReportMail($subject, $reportBody));

            return response()->json([
                'message'  => 'Clocked out successfully! Email sent.',
                'employee' => $employeeName,
                'attendance' => [
                    'id'             => $attendance->id,
                    'clock_in'       => $attendance->clock_in,
                    'clock_out'      => $attendance->clock_out,
                    'hours_worked'   => $attendance->hours_worked ?? null,
                    'method'         => $attendance->method,
                    'report_today'   => $attendance->report_today,
                    'clock_out_image' => $attendance->clock_out_image,
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to clock out.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }



    /**
     * Dashboard Summary
     */
    public function getAttendanceSummary($employeeId)
    {
        $today = Carbon::today();
        $weekStart = Carbon::now()->startOfWeek();
        $monthStart = Carbon::now()->startOfMonth();

        $thisWeekHours = Attendance::where('employee_id', $employeeId)
            ->whereBetween('clock_in', [$weekStart, $today])
            ->sum('hours_worked');

        $thisMonthHours = Attendance::where('employee_id', $employeeId)
            ->whereBetween('clock_in', [$monthStart, $today])
            ->sum('hours_worked');

        $attendanceRate = Attendance::where('employee_id', $employeeId)
            ->whereMonth('clock_in', $today->month)
            ->count();

        $onTimeRate = 95;

        $recent = Attendance::where('employee_id', $employeeId)
            ->orderBy('clock_in', 'desc')
            ->take(5)
            ->get();

        return response()->json([
            'today' => Attendance::where('employee_id', $employeeId)
                ->whereDate('clock_in', $today)
                ->first(),
            'thisWeekHours' => $thisWeekHours,
            'thisMonthHours' => $thisMonthHours,
            'attendanceRate' => $attendanceRate,
            'onTimeRate' => $onTimeRate,
            'recent' => $recent,
        ]);
    }



    public function getMyAttendance(Request $request)
    {
        try {
            $user = auth()->user();
            $employeeId = $user->id;

            $today = now()->toDateString();
            $weekStart = now()->startOfWeek();
            $monthStart = now()->startOfMonth();

            $todayRecord = Attendance::with('approvedAdjustment')
                ->where('employee_id', $employeeId)
                ->whereNull('clock_out')
                ->latest('clock_in')
                ->first();

            if ($todayRecord && $todayRecord->approvedAdjustment) {
                $todayRecord->clock_in = $todayRecord->approvedAdjustment->requested_clock_in;
                $todayRecord->clock_out = $todayRecord->approvedAdjustment->requested_clock_out;
            }

            $thisWeekHours = Attendance::where('employee_id', $employeeId)
                ->whereBetween('clock_in', [$weekStart, now()])
                ->sum('hours_worked');

            $thisMonthHours = Attendance::where('employee_id', $employeeId)
                ->whereBetween('clock_in', [$monthStart, now()])
                ->sum('hours_worked');

            $attendanceCount = Attendance::where('employee_id', $employeeId)
                ->whereMonth('clock_in', now()->month)
                ->count();

            $onTimeRate = 95; // placeholder

            $recent = Attendance::with('approvedAdjustment')
                ->where('employee_id', $employeeId)
                ->orderBy('clock_in', 'desc')
                ->take(5)
                ->get();

            $recent->transform(function ($attendance) {
                if ($attendance->approvedAdjustment) {
                    $attendance->clock_in = $attendance->approvedAdjustment->requested_clock_in;
                    $attendance->clock_out = $attendance->approvedAdjustment->requested_clock_out;
                }
                return $attendance;
            });

            return response()->json([
                'isSuccess' => true,
                'employee' => [
                    'id' => $employeeId,
                    'name' => $user->first_name . ' ' . $user->last_name,
                    'position' => $user->position->title ?? 'N/A',
                    'department' => $user->department->name ?? 'N/A',
                ],
                'todayRecord' => $todayRecord,
                'thisWeekHours' => $thisWeekHours,
                'thisMonthHours' => $thisMonthHours,
                'attendanceRate' => $attendanceCount,
                'onTimeRate' => $onTimeRate,
                'recentAttendance' => $recent,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'isSuccess' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function getMyMonthlyAbsences(Request $request)
    {
        // Validate input
        $request->validate([
            'month' => 'required|integer|min:1|max:12',
            'year'  => 'required|integer'
        ]);

        $user = auth()->user();

        // Start and end of the month
        $start = Carbon::create($request->year, $request->month, 1);
        $end   = $start->copy()->endOfMonth();

        $absentDates = [];

        // Loop through each day of the month
        for ($date = $start->copy(); $date <= $end; $date->addDay()) {

            // Skip weekends
            if ($date->isWeekend()) {
                continue;
            }

            // Skip future dates
            if ($date->gt(now())) {
                continue;
            }

            // Check if employee has attendance record for this day
            $attendanceExists = Attendance::where('employee_id', $user->id)
                ->whereDate('clock_in', $date)
                ->exists();

            // Check if employee has approved leave for this day
            $leaveExists = Leave::where('employee_id', $user->id)
                ->where('status', 'Approved')
                ->whereDate('start_date', '<=', $date)
                ->whereDate('end_date', '>=', $date)
                ->exists();

            // If neither attendance nor leave exists → absent
            if (!$attendanceExists && !$leaveExists) {
                $absentDates[] = $date->toDateString();
            }
        }

        return response()->json([
            'month' => $request->month,
            'year'  => $request->year,
            'absent_dates' => $absentDates,
            'total_absent' => count($absentDates)
        ]);
    }


    public function getMyLeaves(Request $request)
    {
        try {
            //  Get authenticated user
            $user = auth()->user();

            // Check if this user is linked to an employee record
            $employeeId = $user->id;

            // Fetch leave records for this employee
            $leaves = Leave::where('employee_id', $employeeId)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'isSuccess' => true,
                'leaves' => $leaves,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'isSuccess' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }


    //DTR ADJUSTMENTS
    public function requestAdjustment(Request $request, $attendanceId)
    {
        $request->validate([
            'adjusted_clock_in'  => 'nullable|date',
            'adjusted_clock_out' => 'nullable|date',
            'reason'             => 'required|string|max:255',
        ]);

        $attendance = Attendance::findOrFail($attendanceId);
        $employee = auth()->user();

        $adjustedClockIn = $request->adjusted_clock_in
            ? Carbon::parse($request->adjusted_clock_in)
            : null;

        $adjustedClockOut = $request->adjusted_clock_out
            ? Carbon::parse($request->adjusted_clock_out)
            : null;

        $existing = AttendanceAdjustment::where('attendance_id', $attendanceId)
            ->where('status', 'pending')
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'You already have a pending adjustment request for this attendance.'
            ], 400);
        }

        $adjustment = AttendanceAdjustment::create([
            'attendance_id'       => $attendance->id,
            'employee_id'         => $employee->id,
            'requested_clock_in'  => $adjustedClockIn,
            'requested_clock_out' => $adjustedClockOut,
            'reason'              => $request->reason,
            'status'              => 'pending',
        ]);

        // Send Email to HR
        Mail::to('normanparaiso.abm12@gmail.com')
            ->send(new AdjustmentRequestMail($attendance, $employee));

        return response()->json([
            'message'    => 'Adjustment request submitted successfully. HR has been notified.',
            'adjustment' => $adjustment
        ], 200);
    }


    public function getAdjustments(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 10);
            $status = $request->input('status');
            $search = $request->input('search');

            $query = AttendanceAdjustment::with([
                'attendance',
                'employee:id,first_name,last_name,employee_id'
            ]);

            // Optional filter by status
            if ($status) {
                $query->where('status', $status);
            }

            // Optional search
            if ($search) {
                $query->whereHas('employee', function ($q) use ($search) {
                    $q->where('employee_id', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%");
                });
            }

            $adjustments = $query
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);

            return response()->json([
                'message' => 'Attendance adjustments retrieved successfully.',
                'data' => $adjustments,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve adjustments.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getMissedAdjustments(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 10);
            $status = $request->input('status');
            $search = $request->input('search');

            $query = AttendanceAdjustment::with([
                'attendance',
                'employee:id,first_name,last_name,employee_id'
            ])
                ->whereHas('attendance', function ($q) {
                    $q->where('status', 'Missed');
                });

            if ($status) {
                $query->where('status', $status);
            }

            if ($search) {
                $query->whereHas('employee', function ($q) use ($search) {
                    $q->where('employee_id', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%");
                });
            }

            $adjustments = $query
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);

            return response()->json([
                'message' => 'Missed attendance adjustments retrieved successfully.',
                'data' => $adjustments,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve missed adjustments.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    public function approveAdjustment(Request $request, $adjustmentId)
    {
        $request->validate([
            'status' => 'required|in:approved,rejected',
        ]);

        $adjustment = AttendanceAdjustment::findOrFail($adjustmentId);

        $attendance = Attendance::findOrFail($adjustment->attendance_id);

        if ($request->status === 'approved') {

            // Save original values before applying adjustment
            $attendance->original_clock_in = $attendance->clock_in;
            $attendance->original_clock_out = $attendance->clock_out;

            // Apply requested times
            $attendance->clock_in = $adjustment->requested_clock_in;
            $attendance->clock_out = $adjustment->requested_clock_out;

            $attendance->adjusted_by = auth()->id();

            $attendance->save();
        }

        // Update adjustment review
        $adjustment->status = $request->status;
        $adjustment->reviewed_by = auth()->id();
        $adjustment->reviewed_at = now();
        $adjustment->save();

        return response()->json([
            'message' => 'Adjustment ' . $request->status,
            'attendance' => $attendance->fresh(),
            'adjustment' => $adjustment
        ], 200);
    }

    public function requestClockDateAdjustment(Request $request)
    {
        $request->validate([
            'adjusted_clock_date' => 'required|date',
            'adjusted_clock_in'   => 'nullable|date',
            'adjusted_clock_out'  => 'nullable|date',
            'reason'              => 'required|string|max:255',
        ]);

        $employee = auth()->user();

        $adjustedClockIn = $request->adjusted_clock_in
            ? Carbon::parse($request->adjusted_clock_in)
            : null;

        $adjustedClockOut = $request->adjusted_clock_out
            ? Carbon::parse($request->adjusted_clock_out)
            : null;

        // Check if attendance exists for that date
        $attendance = Attendance::where('employee_id', $employee->id)
            ->whereDate('clock_in', $request->adjusted_clock_date)
            ->first();

        // If attendance doesn't exist, create a "placeholder" for reference
        if (!$attendance) {
            $attendance = Attendance::create([
                'employee_id' => $employee->id,
                'status'      => 'Missed',
                'method'      => 'manual',
            ]);
        }

        // Create a new adjustment request
        $adjustment = AttendanceAdjustment::create([
            'attendance_id'       => $attendance->id,
            'employee_id'         => $employee->id,
            'adjusted_clock_date' => $request->adjusted_clock_date,
            'requested_clock_in'  => $adjustedClockIn,
            'requested_clock_out' => $adjustedClockOut,
            'reason'              => $request->reason,
            'status'              => 'pending',
        ]);

        // Send email to HR
        Mail::to('normanparaiso.abm12@gmail.com')
            ->send(new AdjustmentRequestMail($attendance, $employee));

        return response()->json([
            'message'    => 'Adjustment request submitted successfully. HR has been notified.',
            'adjustment' => $adjustment,
        ], 200);
    }




    public function rejectAdjustment(Request $request, $adjustmentId)
    {
        $adjustment = AttendanceAdjustment::findOrFail($adjustmentId);

        // Safety check: only pending adjustments can be rejected
        if ($adjustment->status !== 'pending') {
            return response()->json([
                'message' => 'This adjustment has already been reviewed.'
            ], 400);
        }

        $adjustment->update([
            'status'      => 'rejected',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        return response()->json([
            'message'    => 'Adjustment request rejected successfully.',
            'adjustment' => $adjustment
        ], 200);
    }





    public function getMyAdjustments(Request $request)
    {
        try {
            $employeeId = auth()->id();

            $adjustments = AttendanceAdjustment::with([
                'attendance',
                'employee:id,first_name,last_name,employee_id'
            ])
                ->where('employee_id', $employeeId)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'isSuccess' => true,
                'adjustments' => $adjustments,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'isSuccess' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }



    //Request Leave
    public function requestLeave(Request $request)
    {
        try {
            // Validate input
            $validated = $request->validate([
                'employee_id'   => 'required|exists:employees,id',
                'leave_type_id' => 'required|exists:leave_types,id',
                'start_date'    => 'required|date|after_or_equal:today',
                'end_date'      => 'required|date|after_or_equal:start_date',
                'reason'        => 'nullable|string|max:500',
            ]);

            // Calculate total days
            $days = (new \DateTime($validated['start_date']))
                ->diff(new \DateTime($validated['end_date']))
                ->days + 1;

            //  Fetch leave type details
            $leaveType = LeaveType::findOrFail($validated['leave_type_id']);

            // Fetch employee's leave balance for this leave type
            $employeeLeave = EmployeeLeaveBalance::where('employee_id', $validated['employee_id'])
                ->where('leave_type_id', $validated['leave_type_id'])
                ->first();

            //  If no balance record exists, initialize it with max_days from leave_types
            if (!$employeeLeave) {
                $employeeLeave = EmployeeLeaveBalance::create([
                    'employee_id'   => $validated['employee_id'],
                    'leave_type_id' => $validated['leave_type_id'],
                    'remaining_days' => $leaveType->max_days,
                ]);
            }

            // Check if requested days exceed remaining days
            if ($days > $employeeLeave->remaining_days) {
                return response()->json([
                    'isSuccess' => false,
                    'message'   => "You only have {$employeeLeave->remaining_days} days remaining for this leave type.",
                ], 422);
            }

            //  Prepare fields for leave creation
            $leaveData = [
                'employee_id'   => $validated['employee_id'],
                'leave_type_id' => $validated['leave_type_id'],
                'start_date'    => $validated['start_date'],
                'end_date'      => $validated['end_date'],
                'reason'        => $validated['reason'] ?? null,
                'total_days'    => $days,
                'status'        => 'Pending',
                'is_archived'   => 0, // default value
            ];

            // Save leave
            $leave = Leave::create($leaveData);

            // Deduct days from employee's leave balance
            $employeeLeave->update([
                'remaining_days' => $employeeLeave->remaining_days - $days,
            ]);

            Log::info("Leave request created for employee ID {$validated['employee_id']} ({$days} days, {$leaveType->leave_name})");

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
                'error'     => $e->getMessage(),
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
        if ($fileInput instanceof UploadedFile) {
            return $saveSingleFile($fileInput);
        }

        return null;
    }


    /**
     * Match uploaded face with stored employee faces.
     * This is a placeholder: you must integrate a real face recognition system.
     *
     * @param UploadedFile $uploadedFile
     * @return Employee|null
     */
    private function matchFace(UploadedFile $uploadedFile)
    {
        // Get all employees that have a face_id set
        $employees = Employee::whereNotNull('face_id')->get();

        foreach ($employees as $employee) {
            // Check if that face_id exists in employee_faces
            $faceExists = EmployeeFace::where('id', $employee->face_id)->exists();

            if ($faceExists) {
                return $employee; // Employee recognized
            }
        }

        return null; // No match found
    }




    /**
     * Dummy face comparison function
     * Replace this with actual comparison logic using a Python/AI service or OpenCV
     */
    private function isSameFace($img1Path, $img2Path)
    {
        // Placeholder: In practice, call a face recognition API here
        // For testing, you can match filenames or use a hash as dummy
        return md5_file($img1Path) === md5_file($img2Path);
    }
}
