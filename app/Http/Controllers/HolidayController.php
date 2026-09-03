<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Holiday;
use App\Models\HolidayType;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use App\Models\AnnouncementBoard;

class HolidayController extends Controller
{
    // CREATE
    public function createHoliday(Request $request)
    {
        $validated = $request->validate([
            'holiday_date' => 'required|date|unique:holidays,holiday_date',
            'holiday_name' => 'required|string|max:255',
            'holiday_type_id' => [
                'required',
                'integer',
                Rule::exists('holiday_types', 'id')
                    ->where('is_archived', 0),
            ],
        ]);

        DB::beginTransaction();

        try {
            // Create Holiday
            $holiday = Holiday::create([
                'holiday_date' => $validated['holiday_date'],
                'holiday_name' => $validated['holiday_name'],
                'holiday_type_id' => $validated['holiday_type_id'],
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

            // Load holiday type for response
            $holiday->load('holidayType');

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
        $holidays = Holiday::with('holidayType')
            ->where('is_archived', 0)
            ->orderBy('holiday_date', 'asc')
            ->get();

        return response()->json([
            'isSuccess' => true,
            'message' => 'Holidays retrieved successfully.',
            'data' => $holidays,
        ], 200);
    }


    // READ - Get single holiday
    public function getHoliday($id)
    {
        $holiday = Holiday::with('holidayType')
            ->where('id', $id)
            ->where('is_archived', 0)
            ->first();

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
        $holiday = Holiday::where('id', $id)
            ->where('is_archived', 0)
            ->first();

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

            'holiday_type_id' => [
                'required',
                'integer',
                Rule::exists('holiday_types', 'id')
                    ->where('is_archived', 0),
            ],
        ]);

        $holiday->update([
            'holiday_date' => $validated['holiday_date'],
            'holiday_name' => $validated['holiday_name'],
            'holiday_type_id' => $validated['holiday_type_id'],
        ]);

        // Reload relationship
        $holiday->load('holidayType');

        return response()->json([
            'isSuccess' => true,
            'message' => 'Holiday updated successfully.',
            'data' => $holiday,
        ], 200);
    }


    // ARCHIVE
    public function deleteHoliday($id)
    {
        $holiday = Holiday::where('id', $id)
            ->where('is_archived', 0)
            ->first();

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
