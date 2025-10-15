<?php

namespace App\Http\Controllers;

use App\Models\LeaveType;
use Illuminate\Http\Request;

class LeaveTypeController extends Controller
{
    // ================================
    // ✅ Get All Active Leave Types
    // ================================
    public function getAllLeaveTypes(Request $request)
    {
        $perPage = $request->input('per_page', 10);

        $leaveTypes = LeaveType::where('is_archived', 0)->paginate($perPage);

        if ($leaveTypes->isEmpty()) {
            return response()->json([
                'isSuccess' => false,
                'message'   => 'No active leave types found.',
            ], 404);
        }

        return response()->json([
            'isSuccess'   => true,
            'leave_types' => $leaveTypes->items(),
            'pagination'  => [
                'current_page' => $leaveTypes->currentPage(),
                'per_page'     => $leaveTypes->perPage(),
                'total'        => $leaveTypes->total(),
                'last_page'    => $leaveTypes->lastPage(),
            ],
        ]);
    }

    // ================================
    // ✅ Get Single Leave Type by ID
    // ================================
    public function getLeaveTypeById($id)
    {
        $leaveType = LeaveType::where('id', $id)
            ->where('is_archived', 0)
            ->first();

        if (!$leaveType) {
            return response()->json([
                'isSuccess' => false,
                'message'   => 'Leave type not found or archived.',
            ], 404);
        }

        return response()->json([
            'isSuccess'   => true,
            'leave_type'  => $leaveType,
        ]);
    }

    // ================================
    // Create Leave Type
    // ================================
    public function createLeaveType(Request $request)
    {
        try {
            $validated = $request->validate([
                'leave_name'  => 'required|string|max:150|unique:leave_types,leave_name',
                'description' => 'nullable|string',
                'max_days'    => 'required|integer|min:1',
                'is_paid'     => 'required|boolean',
                'is_active'   => 'nullable|boolean',
            ]);

            $validated['is_archived'] = 0;

            $leaveType = LeaveType::create($validated);

            return response()->json([
                'isSuccess'  => true,
                'message'    => 'Leave type created successfully.',
                'leave_type' => $leaveType,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'isSuccess' => false,
                'message'   => 'Failed to create leave type.',
                'error'     => $e->getMessage(),
            ], 500);
        }
    }

    // ================================
    // ✅ Update Leave Type
    // ================================
    public function updateLeaveType(Request $request, $id)
    {
        $leaveType = LeaveType::where('id', $id)
            ->where('is_archived', 0)
            ->first();

        if (!$leaveType) {
            return response()->json([
                'isSuccess' => false,
                'message'   => 'Leave type not found or archived.',
            ], 404);
        }

        try {
            $validated = $request->validate([
                'leave_name'  => 'sometimes|string|max:150|unique:leave_types,leave_name,' . $id,
                'description' => 'nullable|string',
                'max_days'    => 'sometimes|integer|min:1',
                'is_paid'     => 'sometimes|boolean',
                'is_active'   => 'sometimes|boolean',
            ]);

            $leaveType->update($validated);

            return response()->json([
                'isSuccess'  => true,
                'message'    => 'Leave type updated successfully.',
                'leave_type' => $leaveType,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'isSuccess' => false,
                'message'   => 'Failed to update leave type.',
                'error'     => $e->getMessage(),
            ], 500);
        }
    }

    // ================================
    // ✅ Archive Leave Type (Soft Delete)
    // ================================
    public function archiveLeaveType($id)
    {
        $leaveType = LeaveType::find($id);

        if (!$leaveType || $leaveType->is_archived) {
            return response()->json([
                'isSuccess' => false,
                'message'   => 'Leave type not found or already archived.',
            ], 404);
        }

        $leaveType->update(['is_archived' => 1]);

        return response()->json([
            'isSuccess' => true,
            'message'   => 'Leave type archived successfully.',
        ]);
    }
}
