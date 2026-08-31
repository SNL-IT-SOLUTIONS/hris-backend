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
use App\Models\AnnouncementView;
use App\Models\TrainingModule;
use App\Models\Holiday;

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

            // Total Gross Pay
            $totalGrossPay = PayrollRecord::where('employee_id', $employee->id)
                ->where('is_archived', 0)
                ->sum('gross_pay');

            // Total Net Pay
            $totalNetPay = PayrollRecord::where('employee_id', $employee->id)
                ->where('is_archived', 0)
                ->sum('net_pay');

            // Total Payslip Count (processed)
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
                ->take(20)
                ->get(['id', 'clock_out', 'report_today']);

            // Recent Payslips (Last 3)
            // Recent Payslips (Last 3 - Processed Payroll Periods Only)
            $recentPayslips = PayrollRecord::where('employee_id', $employee->id)
                ->where('is_archived', 0)
                ->whereHas('payrollPeriod', function ($query) {
                    $query->where('status', 'processed')
                        ->where('is_archived', 0);
                })
                ->with('payrollPeriod')
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
                    'total_attendance' => $totalAttendance,
                    'total_gross_pay' => $totalGrossPay,
                    'total_net_pay' => $totalNetPay,
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


    public function adminEODReports(Request $request)
    {
        try {
            $request->validate([
                'date'        => 'nullable|date',
                'employee_id' => 'nullable|integer',
                'status'      => 'nullable|string',
                'is_late'     => 'nullable|boolean',
                'search'      => 'nullable|string|max:255',
                'per_page'    => 'nullable|integer|min:1|max:100',
            ]);

            $date = $request->date
                ? Carbon::parse($request->date)->toDateString()
                : Carbon::now()->toDateString();

            $perPage = $request->per_page ?? 20;

            /*
        |--------------------------------------------------------------------------
        | EOD REPORTS QUERY
        |--------------------------------------------------------------------------
        */

            $query = Attendance::with([
                'employee:id,first_name,middle_name,last_name,employee_id'
            ])
                ->whereNotNull('report_today')
                ->whereDate('clock_out', $date);

            /*
        |--------------------------------------------------------------------------
        | FILTER: EMPLOYEE
        |--------------------------------------------------------------------------
        */

            if ($request->filled('employee_id')) {
                $query->where('employee_id', $request->employee_id);
            }

            /*
        |--------------------------------------------------------------------------
        | FILTER: STATUS
        |--------------------------------------------------------------------------
        */

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            /*
        |--------------------------------------------------------------------------
        | FILTER: LATE
        |--------------------------------------------------------------------------
        */

            if ($request->filled('is_late')) {
                $query->where('is_late', $request->boolean('is_late'));
            }

            /*
        |--------------------------------------------------------------------------
        | FILTER: SEARCH EMPLOYEE
        |--------------------------------------------------------------------------
        */

            if ($request->filled('search')) {

                $search = $request->search;

                $query->whereHas('employee', function ($employeeQuery) use ($search) {

                    $employeeQuery->where(function ($q) use ($search) {

                        $q->where('first_name', 'LIKE', "%{$search}%")
                            ->orWhere('middle_name', 'LIKE', "%{$search}%")
                            ->orWhere('last_name', 'LIKE', "%{$search}%")
                            ->orWhere('employee_id', 'LIKE', "%{$search}%");
                    });
                });
            }

            /*
        |--------------------------------------------------------------------------
        | GET REPORTS
        |--------------------------------------------------------------------------
        */

            $reports = $query
                ->orderBy('clock_out', 'desc')
                ->paginate($perPage);

            /*
        |--------------------------------------------------------------------------
        | SUMMARY QUERY
        |--------------------------------------------------------------------------
        */

            $summaryQuery = Attendance::whereNotNull('report_today')
                ->whereDate('clock_out', $date);

            /*
        |--------------------------------------------------------------------------
        | APPLY SAME FILTERS TO SUMMARY
        |--------------------------------------------------------------------------
        */

            if ($request->filled('employee_id')) {
                $summaryQuery->where(
                    'employee_id',
                    $request->employee_id
                );
            }

            if ($request->filled('status')) {
                $summaryQuery->where(
                    'status',
                    $request->status
                );
            }

            if ($request->filled('is_late')) {
                $summaryQuery->where(
                    'is_late',
                    $request->boolean('is_late')
                );
            }

            if ($request->filled('search')) {

                $search = $request->search;

                $summaryQuery->whereHas('employee', function ($employeeQuery) use ($search) {

                    $employeeQuery->where(function ($q) use ($search) {

                        $q->where('first_name', 'LIKE', "%{$search}%")
                            ->orWhere('middle_name', 'LIKE', "%{$search}%")
                            ->orWhere('last_name', 'LIKE', "%{$search}%")
                            ->orWhere('employee_id', 'LIKE', "%{$search}%");
                    });
                });
            }

            /*
        |--------------------------------------------------------------------------
        | SUMMARY
        |--------------------------------------------------------------------------
        */

            $summary = [
                'total_reports' => (clone $summaryQuery)->count(),

                'total_present' => (clone $summaryQuery)
                    ->where('status', 'Present')
                    ->count(),

                'total_late' => (clone $summaryQuery)
                    ->where('is_late', 1)
                    ->count(),

                'total_on_time' => (clone $summaryQuery)
                    ->where('is_late', 0)
                    ->count(),

                'total_hours_worked' => round(
                    (clone $summaryQuery)->sum('hours_worked'),
                    2
                ),

                'total_late_minutes' => (clone $summaryQuery)
                    ->sum('late_minutes'),

                'total_late_deduction' => round(
                    (clone $summaryQuery)->sum('late_deduction'),
                    2
                ),
            ];

            /*
        |--------------------------------------------------------------------------
        | RESPONSE
        |--------------------------------------------------------------------------
        */

            return response()->json([
                'isSuccess' => true,

                'filters' => [
                    'date'        => $date,
                    'employee_id' => $request->employee_id,
                    'status'      => $request->status,
                    'is_late'     => $request->is_late,
                    'search'      => $request->search,
                ],

                'summary' => $summary,

                'reports' => $reports->items(),

                'pagination' => [
                    'current_page' => $reports->currentPage(),
                    'last_page'    => $reports->lastPage(),
                    'per_page'     => $reports->perPage(),
                    'total'        => $reports->total(),
                ],
            ], 200);
        } catch (\Exception $e) {

            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to load EOD reports.',
                'error' => $e->getMessage(),
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

            // Get holidays for the selected month
            $holidays = Holiday::where('is_archived', 0)
                ->whereBetween('holiday_date', [
                    $start->toDateString(),
                    $end->toDateString()
                ])
                ->get()
                ->keyBy(function ($holiday) {
                    return Carbon::parse($holiday->holiday_date)->toDateString();
                });

            $calendar = [];

            $presentCount = 0;
            $lateCount = 0;
            $missedCount = 0;
            $absentCount = 0;

            for ($date = $start->copy(); $date <= $end; $date->addDay()) {

                $dateString = $date->toDateString();

                // Attendance
                $attendance = Attendance::where('employee_id', $employee->id)
                    ->whereDate('clock_in', $date)
                    ->first();

                // Approved Leave
                $leave = Leave::where('employee_id', $employee->id)
                    ->where('status', 'Approved')
                    ->whereDate('start_date', '<=', $date)
                    ->whereDate('end_date', '>=', $date)
                    ->first();

                $status = 'absent';
                $holiday = null;

                /*
            |--------------------------------------------------------------------------
            | Status Priority
            |--------------------------------------------------------------------------
            | 1. Attendance
            | 2. Leave
            | 3. Holiday
            | 4. Weekend
            | 5. Absent
            |--------------------------------------------------------------------------
            */

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
                } elseif (isset($holidays[$dateString])) {

                    $holiday = $holidays[$dateString];

                    $status = 'holiday';
                } elseif ($date->isWeekend()) {

                    $status = 'weekend';
                } else {

                    $absentCount++;
                }

                $calendar[] = [
                    'date' => $dateString,
                    'status' => $status,

                    'clock_in' => $attendance->clock_in ?? null,
                    'clock_out' => $attendance->clock_out ?? null,

                    'holiday' => $holiday ? [
                        'id' => $holiday->id,
                        'name' => $holiday->holiday_name,
                        'type' => $holiday->holiday_type,
                        'date' => Carbon::parse($holiday->holiday_date)->toDateString(),
                    ] : null,
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
