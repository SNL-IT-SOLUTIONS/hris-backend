<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Models\Leave;
use Exception;


class AttendanceController extends Controller
{
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
        $employeeId = Auth::id(); // ✅ Get the logged-in user's ID

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
        $employeeId = Auth::id(); // ✅ Logged-in user again

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
}
