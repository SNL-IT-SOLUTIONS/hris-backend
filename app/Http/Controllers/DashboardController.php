<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Employee;
use App\Models\Department;
use App\Models\Leave;
use App\Models\Attendance;
use Carbon\Carbon;
use DB;
use Auth;

use App\Models\LeaveType;
use App\Models\EmployeeLeaveBalance;
use App\Models\EmployeeLeaveRequest;
use App\Models\PayrollRecord;
use App\Models\Announcement;
use App\Models\AnnouncementBoard;

class DashboardController extends Controller
{
    public function index()
    {
        $today = Carbon::today();

        // TOTAL EMPLOYEES
        $totalEmployees = Employee::where('is_active', 1)
            ->where('is_archived', 0)
            ->count();

        // TOTAL DEPARTMENTS
        $totalDepartments = Department::where('is_archived', 0)->count();

        // PENDING LEAVES
        $pendingLeaves = Leave::where('status', 'Pending')
            ->where('is_archived', 0)
            ->count();

        // TODAY'S ATTENDANCES
        $presentToday = Attendance::whereDate('clock_in', $today)
            ->where('status', 'Present')
            ->count();

        $lateToday = Attendance::whereDate('clock_in', $today)
            ->where('status', 'Late')
            ->count();

        // ABSENT = total employees - (present + late)
        $absentToday = $totalEmployees - ($presentToday + $lateToday);
        if ($absentToday < 0) $absentToday = 0;

        // Attendance Rate
        $attendanceRate = $totalEmployees > 0
            ? round((($presentToday + $lateToday) / $totalEmployees) * 100)
            : 0;

        // Department Overview (employee count per department)
        $departmentOverview = Department::where('is_archived', 0)
            ->withCount([
                'employees' => function ($q) {
                    $q->where('is_active', 1)->where('is_archived', 0);
                }
            ])
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'total_employees' => $totalEmployees,
                'total_departments' => $totalDepartments,
                'pending_leaves' => $pendingLeaves,
                'attendance_rate' => $attendanceRate,
                'today_attendance' => [
                    'present' => $presentToday,
                    'late' => $lateToday,
                    'absent' => $absentToday,
                ],
                'department_overview' => $departmentOverview
            ]
        ]);
    }

    public function employeesDashboard()
    {
        try {
            $employee = auth()->user();

            if (!$employee) {
                return response()->json([
                    'message' => 'Unauthorized.'
                ], 401);
            }

            /*
        |--------------------------------------------------------------------------
        | OVERVIEW STATS
        |--------------------------------------------------------------------------
        */

            // Total Attendance (Present only)
            $totalAttendance = Attendance::where('employee_id', $employee->id)
                ->where('status', 'Present')
                ->count();

            // Total Net Pays
            $totalNetPays = PayrollRecord::where('employee_id', $employee->id)
                ->where('is_archived', 0)
                ->sum('net_pay');

            $totalPayslipAmount = PayrollRecord::where('employee_id', $employee->id)
                ->where('is_archived', 0)
                ->sum('gross_pay');

            $totalPayslipCount = PayrollRecord::where('employee_id', $employee->id)
                ->where('is_archived', 0)
                ->whereHas('payrollPeriod', function ($q) {
                    $q->where('status', 'processed');
                })
                ->count();


            /*
        |--------------------------------------------------------------------------
        | RECENT DATA
        |--------------------------------------------------------------------------
        */

            // Recent Attendance (Last 5)
            $recentAttendance = Attendance::where('employee_id', $employee->id)
                ->orderBy('clock_in', 'desc')
                ->take(5)
                ->get();

            // Recent End of Day Reports (Last 5 with report_today not null)
            $recentReports = Attendance::where('employee_id', $employee->id)
                ->whereNotNull('report_today')
                ->orderBy('clock_out', 'desc')
                ->take(5)
                ->get(['id', 'clock_out', 'report_today']);

            // Recent Payslips (Last 5)
            $recentPayslips = PayrollRecord::where('employee_id', $employee->id)
                ->where('is_archived', 0)
                ->orderBy('created_at', 'desc')
                ->take(3)
                ->get();


            /*
        |--------------------------------------------------------------------------
        | ANNOUNCEMENTS
        |--------------------------------------------------------------------------
        */

            $now = Carbon::now();

            $announcements = AnnouncementBoard::where('is_active', 1)
                ->where('is_archived', 0)
                ->where(function ($query) use ($now) {
                    $query->whereNull('publish_at')
                        ->orWhere('publish_at', '<=', $now);
                })
                ->where(function ($query) use ($now) {
                    $query->whereNull('expire_at')
                        ->orWhere('expire_at', '>=', $now);
                })
                ->orderBy('created_at', 'desc')
                ->take(4)
                ->get(['id', 'title', 'content', 'publish_at', 'expire_at', 'created_at']);


            return response()->json([
                'overview' => [
                    'total_gross_pays' => $totalPayslipAmount,
                    'total_attendance' => $totalAttendance,
                    'total_net_pays' => $totalNetPays,
                    'total_payslip_amount' => $totalPayslipAmount,
                    'total_payslip_count' => $totalPayslipCount,
                ],

                'announcements' => $announcements,
                'recent_reports' => $recentReports,
                'recent_payslips' => $recentPayslips,
                'recent_attendance' => $recentAttendance,

            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to load dashboard.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function attendanceCalendarDashboard(Request $request)
    {
        try {

            $request->validate([
                'month' => 'required|integer|min:1|max:12',
                'year'  => 'required|integer'
            ]);

            $employee = auth()->user();

            if (!$employee) {
                return response()->json([
                    'message' => 'Unauthorized.'
                ], 401);
            }

            $start = Carbon::create($request->year, $request->month, 1);
            $end   = $start->copy()->endOfMonth();

            $calendar = [];

            $presentCount = 0;
            $lateCount = 0;
            $missedCount = 0;
            $absentCount = 0;

            for ($date = $start->copy(); $date <= $end; $date->addDay()) {

                $attendance = Attendance::where('employee_id', $employee->id)
                    ->whereDate('clock_in', $date)
                    ->first();

                $leave = Leave::where('employee_id', $employee->id)
                    ->where('status', 'Approved')
                    ->whereDate('start_date', '<=', $date)
                    ->whereDate('end_date', '>=', $date)
                    ->first();

                $status = 'absent';

                if ($attendance) {
                    $status = strtolower($attendance->status);

                    if ($status === 'present') {
                        $presentCount++;
                    } elseif ($status === 'late') {
                        $lateCount++;
                    } elseif ($status === 'missed') {
                        $missedCount++;
                    }
                } elseif ($leave) {
                    $status = 'leave';
                } elseif ($date->isWeekend()) {
                    $status = 'weekend';
                } else {
                    $absentCount++;
                }

                $calendar[] = [
                    'date' => $date->toDateString(),
                    'status' => $status,
                    'clock_in' => $attendance->clock_in ?? null,
                    'clock_out' => $attendance->clock_out ?? null,
                ];
            }

            return response()->json([
                'success' => true,

                'employee' => [
                    'id' => $employee->id,
                    'name' => $employee->first_name . ' ' . $employee->last_name
                ],

                'month' => $start->format('F'),
                'year'  => $start->year,

                'summary' => [
                    'present' => $presentCount,
                    'late' => $lateCount,
                    'missed' => $missedCount,
                    'absent' => $absentCount
                ],

                'calendar' => $calendar

            ], 200);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Failed to load attendance dashboard.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function monthlyAttendanceDashboard()
    {
        try {

            $user = auth()->user();

            $start = now()->startOfMonth();
            $end   = now()->endOfMonth();

            $presentCount = Attendance::where('employee_id', $user->id)
                ->where('status', 'Present')
                ->whereBetween('clock_in', [$start, $end])
                ->count();

            $missedCount = Attendance::where('employee_id', $user->id)
                ->where('status', 'missed')
                ->whereBetween('clock_in', [$start, $end])
                ->count();

            $absentCount = 0;

            for ($date = $start->copy(); $date <= $end; $date->addDay()) {

                if ($date->isWeekend()) {
                    continue;
                }

                if ($date->gt(now())) {
                    continue;
                }

                $attendanceExists = Attendance::where('employee_id', $user->id)
                    ->whereDate('clock_in', $date)
                    ->exists();

                $leaveExists = Leave::where('employee_id', $user->id)
                    ->where('status', 'Approved')
                    ->whereDate('start_date', '<=', $date)
                    ->whereDate('end_date', '>=', $date)
                    ->exists();

                if (!$attendanceExists && !$leaveExists) {
                    $absentCount++;
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'month'   => $start->format('F'),
                    'year'    => $start->year,
                    'present' => $presentCount,
                    'missed'  => $missedCount,
                    'absent'  => $absentCount
                ]
            ], 200);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Failed to load monthly attendance data.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
