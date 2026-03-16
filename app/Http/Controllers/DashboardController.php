<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Employee;
use App\Models\Department;
use App\Models\Leave;
use App\Models\Attendance;
use Carbon\Carbon;

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
}
