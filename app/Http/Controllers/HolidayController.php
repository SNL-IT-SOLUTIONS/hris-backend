<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Holiday;
use Illuminate\Validation\Rule;

class HolidayController extends Controller
{
    // CREATE
    public function createHoliday(Request $request)
    {
        $validated = $request->validate([
            'holiday_date' => 'required|date|unique:holidays,holiday_date',
            'holiday_name' => 'required|string|max:255',
            'holiday_type' => ['required', Rule::in(['Regular', 'Special'])],
        ]);

        $holiday = Holiday::create([
            'holiday_date' => $validated['holiday_date'],
            'holiday_name' => $validated['holiday_name'],
            'holiday_type' => $validated['holiday_type'],
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
            'holiday_date' => [
                'required',
                'date',
                Rule::unique('holidays', 'holiday_date')->ignore($id),
            ],
            'holiday_name' => 'required|string|max:255',
            'holiday_type' => ['required', Rule::in(['Regular', 'Special'])],
        ]);

        $holiday->update([
            'holiday_date' => $validated['holiday_date'],
            'holiday_name' => $validated['holiday_name'],
            'holiday_type' => $validated['holiday_type'],
        ]);

        return response()->json([
            'isSuccess' => true,
            'message' => 'Holiday updated successfully.',
            'data' => $holiday,
        ], 200);
    }

    // ARCHIVE
    public function deleteHoliday($id)
    {
        $holiday = Holiday::find($id);

        if (!$holiday) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Holiday not found.',
            ], 404);
        }

        $holiday->is_archived = 1;
        $holiday->save();

        return response()->json([
            'isSuccess' => true,
            'message' => 'Holiday archived successfully.',
        ], 200);
    }
}
