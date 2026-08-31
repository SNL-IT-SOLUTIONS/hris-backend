<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Holiday;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use App\Models\AnnouncementBoard;


class HolidayController extends Controller
{


    public function createHoliday(Request $request)
    {
        $validated = $request->validate([
            'holiday_date' => 'required|date|unique:holidays,holiday_date',
            'holiday_name' => 'required|string|max:255',
            'holiday_type' => ['required', Rule::in(['Regular', 'Special'])],
        ]);

        DB::beginTransaction();

        try {

            // Create Holiday
            $holiday = Holiday::create([
                'holiday_date' => $validated['holiday_date'],
                'holiday_name' => $validated['holiday_name'],
                'holiday_type' => $validated['holiday_type'],
                'is_archived' => 0,
            ]);

            // Create Announcement
            $announcement = AnnouncementBoard::create([
                'title' => $validated['holiday_name'],
                'content' => 'Please be informed that ' .
                    $validated['holiday_name'] .
                    ' will be observed on ' .
                    date('F d, Y', strtotime($validated['holiday_date'])) .
                    '.',

                'posted_by' => auth()->id(),
                'is_archived' => 0,
                'is_active' => 1,
                'publish_at' => now(),
                'expire_at' => null,
            ]);

            DB::commit();

            return response()->json([
                'isSuccess' => true,
                'message' => 'Holiday and announcement created successfully.',
                'data' => [
                    'holiday' => $holiday,
                    'announcement' => $announcement,
                ],
            ], 201);
        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to create holiday and announcement.',
                'error' => $e->getMessage(),
            ], 500);
        }
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
