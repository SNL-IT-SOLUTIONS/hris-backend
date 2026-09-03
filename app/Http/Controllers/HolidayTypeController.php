<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\HolidayType;

class HolidayTypeController extends Controller
{
    // GET ALL HOLIDAY TYPES
    public function getHolidayTypes()
    {
        try {
            $holidayTypes = HolidayType::where('is_archived', 0)
                ->orderBy('id', 'asc')
                ->get();

            return response()->json([
                'isSuccess' => true,
                'holidayTypes' => $holidayTypes
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to retrieve holiday types.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // GET SINGLE HOLIDAY TYPE
    public function getHolidayType($id)
    {
        try {
            $holidayType = HolidayType::where('id', $id)
                ->where('is_archived', 0)
                ->first();

            if (!$holidayType) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'Holiday type not found.'
                ], 404);
            }

            return response()->json([
                'isSuccess' => true,
                'holidayType' => $holidayType
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to retrieve holiday type.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // CREATE HOLIDAY TYPE
    public function createHolidayType(Request $request)
    {
        $validated = $request->validate([
            'type_name' => 'required|string|max:255|unique:holiday_types,type_name',
            'description' => 'nullable|string'
        ]);

        try {
            $holidayType = HolidayType::create([
                'type_name' => $validated['type_name'],
                'description' => $validated['description'] ?? null,
                'is_archived' => 0
            ]);

            return response()->json([
                'isSuccess' => true,
                'message' => 'Holiday type created successfully.',
                'holidayType' => $holidayType
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to create holiday type.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // UPDATE HOLIDAY TYPE
    public function updateHolidayType(Request $request, $id)
    {
        $validated = $request->validate([
            'type_name' => 'required|string|max:255|unique:holiday_types,type_name,' . $id,
            'description' => 'nullable|string'
        ]);

        try {
            $holidayType = HolidayType::where('id', $id)
                ->where('is_archived', 0)
                ->first();

            if (!$holidayType) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'Holiday type not found.'
                ], 404);
            }

            $holidayType->update([
                'type_name' => $validated['type_name'],
                'description' => $validated['description'] ?? null
            ]);

            return response()->json([
                'isSuccess' => true,
                'message' => 'Holiday type updated successfully.',
                'holidayType' => $holidayType
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to update holiday type.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // ARCHIVE HOLIDAY TYPE
    public function archiveHolidayType($id)
    {
        try {
            $holidayType = HolidayType::where('id', $id)
                ->where('is_archived', 0)
                ->first();

            if (!$holidayType) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'Holiday type not found.'
                ], 404);
            }

            $holidayType->update([
                'is_archived' => 1
            ]);

            return response()->json([
                'isSuccess' => true,
                'message' => 'Holiday type archived successfully.'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to archive holiday type.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
