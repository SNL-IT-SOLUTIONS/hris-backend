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

            // Total Gross Amount of Payslips
            $totalPayslipAmount = PayrollRecord::where('employee_id', $employee->id)
                ->where('is_archived', 0)
                ->sum('gross_pay');


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
                ->take(5)
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
                ->orderBy('publish_at', 'desc')
                ->take(5)
                ->get(['id', 'title', 'content', 'publish_at', 'expire_at', 'created_at']);


            return response()->json([
                'overview' => [
                    'total_attendance' => $totalAttendance,
                    'total_absents' => $totalAbsents,
                    'total_net_pays' => $totalNetPays,
                    'total_payslip_amount' => $totalPayslipAmount,
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
}
