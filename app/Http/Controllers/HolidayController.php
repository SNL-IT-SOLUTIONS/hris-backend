<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Holiday;

class HolidayController extends Controller
{
    // CREATE
    public function createHoliday(Request $request)
    {
        $validated = $request->validate([
            'holiday_date' => 'required|date|unique:holidays,holiday_date',
        ]);

        $holiday = Holiday::create([
            'holiday_date' => $validated['holiday_date'],
        ]);

        return response()->json([
            'isSuccess' => true,
            'message' => 'Holiday added successfully.',
            'data' => $holiday,
        ], 201);
    }

    // READ - Get all holidays
    public function getHolidays()
    {
        $holidays = Holiday::orderBy('holiday_date', 'asc')->get();

        return response()->json([
            'isSuccess' => true,
            'message' => 'Holidays retrieved successfully.',
            'data' => $holidays,
        ], 200);
    }

    // READ - Get single holiday
    public function getHoliday($id)
    {
        $holiday = Holiday::find($id);

        if (!$holiday) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Holiday not found.',
            ], 404);
        }

        return response()->json([
            'isSuccess' => true,
            'message' => 'Holiday retrieved successfully.',
            'data' => $holiday,
        ], 200);
    }

    // UPDATE
    public function updateHoliday(Request $request, $id)
    {
        $holiday = Holiday::find($id);

        if (!$holiday) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Holiday not found.',
            ], 404);
        }

        $validated = $request->validate([
            'holiday_date' => 'required|date|unique:holidays,holiday_date,' . $id,
        ]);

        $holiday->update([
            'holiday_date' => $validated['holiday_date'],
        ]);

        return response()->json([
            'isSuccess' => true,
            'message' => 'Holiday updated successfully.',
            'data' => $holiday,
        ], 200);
    }

    // DELETE
    public function deleteHoliday($id)
    {
        $holiday = Holiday::find($id);

        if (!$holiday) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Holiday not found.',
            ], 404);
        }

        $holiday->delete();

        return response()->json([
            'isSuccess' => true,
            'message' => 'Holiday deleted successfully.',
        ], 200);
    }
}
