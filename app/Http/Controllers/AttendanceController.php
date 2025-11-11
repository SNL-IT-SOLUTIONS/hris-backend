<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Models\Leave;
use Illuminate\Support\Facades\Log;
use App\Models\LeaveType;
use App\Models\Employee;
use Illuminate\Http\UploadedFile;

use Exception;


class AttendanceController extends Controller
{

    use Illuminate\Http\UploadedFile;

    public function registerFace(Request $request)
    {
        try {
            $user = auth()->user();

            $request->validate([
                'face_image' => 'required|file|image|mimes:jpeg,jpg,png|max:5120',
            ]);

            // Use your helper to save
            $file = $request->file('face_image');
            $path = $this->saveFileToPublic($file, 'face_' . $user->id);

            $user->update(['face_path' => $path]);

            return response()->json([
                'message' => 'Face successfully registered!',
                'employee' => [
                    'id' => $user->id,
                    'name' => "{$user->first_name} {$user->last_name}",
                    'face_image_url' => asset($path),
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
        $attendances = Attendance::with(['employee:id,first_name,last_name,email,department_id,position_id'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'isSuccess' => true,
            'data' => $attendances,
        ]);
    }


    public function getAllLeaves()
    {
        $leaves = Leave::with(['employee', 'leaveType'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'isSuccess' => true,
            'data' => $leaves,
        ]);
    }


    public function confirmleave(Request $request, $id)
    {
        $leave = Leave::find($id);

        if (!$leave) {
            return response()->json(['isSuccess' => false, 'message' => 'Leave request not found.'], 404);
        }

        // Validate status input
        $request->validate([
            'status' => 'required|in:Approved,Rejected',
        ]);

        $leave->status = $request->status;
        $leave->save();

        return response()->json([
            'isSuccess' => true,
            'message'   => 'Leave request ' . strtolower($request->status) . ' successfully.',
            'leave'     => $leave,
        ]);
    }



    /**
     * Clock In
     */


    public function clockIn(Request $request)
    {
        $employeeId = Auth::id(); // Get the logged-in user's ID

        $existing = Attendance::where('employee_id', $employeeId)
            ->whereDate('clock_in', Carbon::today())
            ->first();

        if ($existing) {
            return response()->json(['message' => 'Already clocked in today.'], 400);
        }

        $attendance = Attendance::create([
            'employee_id' => $employeeId,
            'clock_in' => Carbon::now(),
            'status' => 'Present',
        ]);

        return response()->json([
            'message' => 'Clocked in successfully!',
            'data' => $attendance,
        ]);
    }

    public function clockOut(Request $request)
    {
        $employeeId = Auth::id(); // Logged-in user again

        $attendance = Attendance::where('employee_id', $employeeId)
            ->whereDate('clock_in', Carbon::today())
            ->first();

        if (!$attendance) {
            return response()->json(['message' => 'You have not clocked in yet.'], 400);
        }

        if ($attendance->clock_out) {
            return response()->json(['message' => 'Already clocked out today.'], 400);
        }

        $attendance->clock_out = Carbon::now();
        $attendance->calculateHoursWorked();
        $attendance->save();

        return response()->json([
            'message' => 'Clocked out successfully!',
            'data' => $attendance,
        ]);
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
            //  Get authenticated user
            $user = auth()->user();

            // Check if this user is linked to an employee record
            $employeeId = $user->id;

            $today = now()->toDateString();
            $weekStart = now()->startOfWeek();
            $monthStart = now()->startOfMonth();

            // Fetch today’s record
            $todayRecord = Attendance::where('employee_id', $employeeId)
                ->whereDate('clock_in', $today)
                ->first();

            // Compute summaries
            $thisWeekHours = Attendance::where('employee_id', $employeeId)
                ->whereBetween('clock_in', [$weekStart, now()])
                ->sum('hours_worked');

            $thisMonthHours = Attendance::where('employee_id', $employeeId)
                ->whereBetween('clock_in', [$monthStart, now()])
                ->sum('hours_worked');

            $attendanceCount = Attendance::where('employee_id', $employeeId)
                ->whereMonth('clock_in', now()->month)
                ->count();

            // Example on-time rate (placeholder logic)
            $onTimeRate = 95;

            // Recent attendance records
            $recent = Attendance::where('employee_id', $employeeId)
                ->orderBy('clock_in', 'desc')
                ->take(5)
                ->get();

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


    //Request Leave
    public function requestLeave(Request $request)
    {
        try {
            // ✅ Validate input
            $validated = $request->validate([
                'employee_id'   => 'required|exists:employees,id',
                'leave_type_id' => 'required|exists:leave_types,id', // fixed typo: "extists" → "exists"
                'start_date'    => 'required|date|after_or_equal:today',
                'end_date'      => 'required|date|after_or_equal:start_date',
                'reason'        => 'nullable|string|max:500',
            ]);

            // 🧮 Calculate total days
            $days = (new \DateTime($validated['start_date']))
                ->diff(new \DateTime($validated['end_date']))
                ->days + 1;

            // 🧾 Fetch leave type details
            $leaveType = \App\Models\LeaveType::find($validated['leave_type_id']);

            // 🧩 Optional: Check if leave exceeds max_days
            if ($leaveType && $leaveType->max_days && $days > $leaveType->max_days) {
                return response()->json([
                    'isSuccess' => false,
                    'message'   => "This leave type allows a maximum of {$leaveType->max_days} days.",
                ], 422);
            }

            // ✍️ Add extra fields
            $validated['total_days'] = $days;
            $validated['status'] = 'Pending';
            $validated['is_paid'] = $leaveType->is_paid ?? false;

            // ✅ Save to DB
            $leave = \App\Models\Leave::create($validated);

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
