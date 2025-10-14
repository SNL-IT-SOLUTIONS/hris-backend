<?php

namespace App\Http\Controllers;

use App\Models\WorkLocation;
use Illuminate\Http\Request;

class WorkLocationController extends Controller
{
    // ✅ Get all active work locations
    public function getWorkLocations()
    {
        $locations = WorkLocation::where('is_archived', false)->get();

        return response()->json([
            'isSuccess' => true,
            'message' => 'Work locations retrieved successfully.',
            'work_locations' => $locations
        ]);
    }

    // ✅ Create new work location
    public function createWorkLocation(Request $request)
    {
        $validated = $request->validate([
            'location_name' => 'required|string|max:150|unique:work_locations,location_name',
            'address' => 'nullable|string|max:255',
            'location_type' => 'required|in:Physical Office,Remote,Hybrid',
        ]);

        $location = WorkLocation::create($validated);

        return response()->json([
            'isSuccess' => true,
            'message' => 'Work location created successfully.',
            'work_location' => $location
        ], 201);
    }

    // ✅ Get a single work location
    public function getWorkLocation($id)
    {
        $location = WorkLocation::find($id);

        if (!$location) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Work location not found.'
            ], 404);
        }

        return response()->json([
            'isSuccess' => true,
            'message' => 'Work location retrieved successfully.',
            'work_location' => $location
        ]);
    }

    // ✅ Update work location
    public function updateWorkLocation(Request $request, $id)
    {
        $location = WorkLocation::find($id);

        if (!$location) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Work location not found.'
            ], 404);
        }

        $validated = $request->validate([
            'location_name' => 'sometimes|string|max:150|unique:work_locations,location_name,' . $location->id,
            'address' => 'nullable|string|max:255',
            'location_type' => 'sometimes|in:Physical Office,Remote,Hybrid',
        ]);

        $location->update($validated);

        return response()->json([
            'isSuccess' => true,
            'message' => 'Work location updated successfully.',
            'work_location' => $location
        ]);
    }

    // ✅ Archive work location instead of deleting
    public function archiveWorkLocation($id)
    {
        $location = WorkLocation::find($id);

        if (!$location) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Work location not found.'
            ], 404);
        }

        $location->update(['is_archived' => true]);

        return response()->json([
            'isSuccess' => true,
            'message' => 'Work location archived successfully.'
        ]);
    }
}
