<?php

namespace App\Http\Controllers;

use App\Models\PositionType;
use Illuminate\Http\Request;

class PositionTypeController extends Controller
{
    /**
     * Get all active position types
     */
    public function getAllPositionTypes(Request $request)
    {
        $perPage = $request->input('per_page', 10);

        $positions = PositionType::where('is_archived', 0)
            ->paginate($perPage);

        if ($positions->isEmpty()) {
            return response()->json([
                'isSuccess' => false,
                'message'   => 'No active position types found.',
            ], 404);
        }

        return response()->json([
            'isSuccess'      => true,
            'position_types' => $positions->items(),
            'pagination'     => [
                'current_page' => $positions->currentPage(),
                'per_page'     => $positions->perPage(),
                'total'        => $positions->total(),
                'last_page'    => $positions->lastPage(),
            ],
        ]);
    }

    /**
     * Get single position type by ID
     */
    public function getPositionTypeById($id)
    {
        $position = PositionType::where('id', $id)
            ->where('is_archived', 0)
            ->first();

        if (!$position) {
            return response()->json([
                'isSuccess' => false,
                'message'   => 'Position type not found or archived.',
            ], 404);
        }

        return response()->json([
            'isSuccess'      => true,
            'position_type'  => $position,
        ]);
    }

    /**
     * Create new position type
     */
    public function createPositionType(Request $request)
    {
        try {
            $validated = $request->validate([
                'position_name' => 'required|string|max:150|unique:position_types,position_name',
                'description'   => 'nullable|string',
                'is_active'     => 'nullable|boolean',
            ]);

            $validated['is_archived'] = 0;

            $position = PositionType::create($validated);

            return response()->json([
                'isSuccess'     => true,
                'message'       => 'Position type created successfully.',
                'position_type' => $position,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'isSuccess' => false,
                'message'   => 'Failed to create position type.',
                'error'     => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update existing position type
     */
    public function updatePositionType(Request $request, $id)
    {
        $position = PositionType::where('id', $id)->where('is_archived', 0)->first();

        if (!$position) {
            return response()->json([
                'isSuccess' => false,
                'message'   => 'Position type not found or archived.',
            ], 404);
        }

        try {
            $validated = $request->validate([
                'position_name' => 'sometimes|string|max:150|unique:position_types,position_name,' . $id,
                'description'   => 'sometimes|nullable|string',
                'is_active'     => 'sometimes|boolean',
            ]);

            $position->update($validated);

            return response()->json([
                'isSuccess'     => true,
                'message'       => 'Position type updated successfully.',
                'position_type' => $position,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'isSuccess' => false,
                'message'   => 'Failed to update position type.',
                'error'     => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Archive (soft delete) position type
     */
    public function archivePositionType($id)
    {
        $position = PositionType::find($id);

        if (!$position || $position->is_archived) {
            return response()->json([
                'isSuccess' => false,
                'message'   => 'Position type not found or already archived.',
            ], 404);
        }

        $position->update(['is_archived' => 1]);

        return response()->json([
            'isSuccess' => true,
            'message'   => 'Position type archived successfully.',
        ]);
    }
}
