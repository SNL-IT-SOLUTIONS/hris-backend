<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Models\Leave;
use Illuminate\Support\Facades\Log;
use App\Models\LeaveType;
use App\Models\Employee;
use App\Models\EmployeeFace;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

use Exception;


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
        try {
            $validator = Validator::make($request->all(), [
                'face_image' => 'required|file|image|mimes:jpeg,png,jpg|max:5120',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Invalid image.',
                    'errors' => $validator->errors()
                ], 422);
            }

            $uploadedFile = $request->file('face_image');

            // Match face with employee
            $employee = $this->matchFace($uploadedFile);

            if (!$employee) {
                return response()->json(['message' => 'Face not recognized.'], 401);
            }

            // Check if already clocked in today
            $existing = Attendance::where('employee_id', $employee->id)
                ->whereDate('clock_in', Carbon::today())
                ->first();

            if ($existing) {
                return response()->json(['message' => 'Already clocked in today.'], 400);
            }

            // Save clock-in image
            $imagePath = $this->saveFileToPublic($uploadedFile, 'attendance_in_' . $employee->id . '_' . time());

            $attendance = Attendance::create([
                'employee_id' => $employee->id,
                'clock_in' => Carbon::now(),
                'status' => 'Present',
                'method' => 'Manual',
                'image_path' => $imagePath, // clock-in image
            ]);

            return response()->json([
                'message' => 'Clocked in successfully!',
                'employee' => $employee->first_name . ' ' . $employee->last_name,
                'attendance' => $attendance,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to clock in.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function clockOut(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'face_image' => 'required|file|image|mimes:jpeg,png,jpg|max:5120',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Invalid image.',
                    'errors' => $validator->errors()
                ], 422);
            }

            $uploadedFile = $request->file('face_image');

            // Match face with employee
            $employee = $this->matchFace($uploadedFile);

            if (!$employee) {
                return response()->json(['message' => 'Face not recognized.'], 401);
            }

            $attendance = Attendance::where('employee_id', $employee->id)
                ->whereDate('clock_in', Carbon::today())
                ->first();

            if (!$attendance) {
                return response()->json(['message' => 'You have not clocked in yet.'], 400);
            }

            if ($attendance->clock_out) {
                return response()->json(['message' => 'Already clocked out today.'], 400);
            }

            // Save clock-out image
            $imagePath = $this->saveFileToPublic($uploadedFile, 'attendance_out_' . $employee->id . '_' . time());

            $attendance->clock_out = Carbon::now();
            $attendance->clock_out_image_path = $imagePath; // clock-out image
            if (method_exists($attendance, 'calculateHoursWorked')) {
                $attendance->calculateHoursWorked();
            }
            $attendance->save();

            return response()->json([
                'message' => 'Clocked out successfully!',
                'employee' => $employee->first_name . ' ' . $employee->last_name,
                'attendance' => $attendance,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to clock out.',
                'error' => $e->getMessage()
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
                'leave_type_id' => 'required|exists:leave_types,id',
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
