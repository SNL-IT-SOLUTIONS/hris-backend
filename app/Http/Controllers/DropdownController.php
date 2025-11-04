<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\WorkLocation;
use App\Models\Employee;
use App\Models\PositionType;
use App\Models\BenefitType;

class DropdownController extends Controller
{
    /**
     * 🔽 Get all departments for dropdown
     */
    public function getDepartments()
    {
        $departments = Department::where('is_archived', false)
            ->where('is_active', true)
            ->select('id', 'department_name')
            ->orderBy('department_name', 'asc')
            ->get();

        if ($departments->isEmpty()) {
            return response()->json([
                'isSuccess' => false,
                'message'   => 'No departments found.',
            ], 404);
        }

        return response()->json([
            'isSuccess' => true,
            'message'   => 'Departments retrieved successfully.',
            'data'      => $departments,
        ]);
    }

    /**
     * 🏢 Get all work locations for dropdown
     */
    public function getWorkLocations()
    {
        $locations = WorkLocation::where('is_archived', false)
            ->select('id', 'location_name')
            ->orderBy('location_name', 'asc')
            ->get();

        if ($locations->isEmpty()) {
            return response()->json([
                'isSuccess' => false,
                'message'   => 'No work locations found.',
            ], 404);
        }

        return response()->json([
            'isSuccess' => true,
            'message'   => 'Work locations retrieved successfully.',
            'data'      => $locations,
        ]);
    }

    public function getEmployeesDropdown()
    {
        try {
            $employees = Employee::select('id', 'first_name', 'last_name')
                ->where('is_archived', 0)
                ->orderBy('first_name')
                ->get()
                ->map(function ($emp) {
                    return [
                        'id' => $emp->id,
                        'name' => "{$emp->first_name} {$emp->last_name}"
                    ];
                });

            return response()->json([
                'isSuccess' => true,
                'message' => 'Employee dropdown retrieved successfully.',
                'data' => $employees
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to load employees dropdown.',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function getPostionTypesDropdown()
    {
        try {
            $employees = PositionType::select('id', 'position_name')
                ->where('is_archived', 0)
                ->orderBy('position_name', 'asc')
                ->get()
                ->map(function ($position) {
                    return [
                        'id' => $position->id,
                        'position_name' => "$position->position_name"
                    ];
                });

            return response()->json([
                'isSuccess' => true,
                'message' => 'Employee dropdown retrieved successfully.',
                'data' => $employees
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to load employees dropdown.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getInterviewersDropdown()
    {
        try {
            $interviewers = Employee::select('id', 'first_name', 'last_name')
                ->where('is_archived', 0)
                ->where('is_interviewer', 1)
                ->orderBy('first_name')
                ->get()
                ->map(function ($emp) {
                    return [
                        'id' => $emp->id,
                        'interviewer_name' => "{$emp->first_name} {$emp->last_name}"
                    ];
                });

            return response()->json([
                'isSuccess' => true,
                'message' => 'Interviewers dropdown retrieved successfully.',
                'data' => $interviewers
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to load interviewers dropdown.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getBenefitTypesDropdown()
    {
        try {
            $benefitTypes = BenefitType::select('id', 'benefit_name')
                ->where('is_active', 1)
                ->orderBy('benefit_name', 'asc')
                ->get();

            return response()->json([
                'isSuccess' => true,
                'message' => 'Benefit types dropdown retrieved successfully.',
                'data' => $benefitTypes
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to load benefit types dropdown.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
