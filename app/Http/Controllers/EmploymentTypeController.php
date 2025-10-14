<?php

namespace App\Http\Controllers;

use App\Models\EmploymentType;
use Illuminate\Http\Request;

class EmploymentTypeController extends Controller
{
    // ✅ Get all employment types (excluding archived)
    public function getEmploymentTypes()
    {
        $types = EmploymentType::where('is_archived', 0)->get();

        return response()->json([
            'isSuccess' => true,
            'message' => 'Employment types retrieved successfully.',
            'employment_types' => $types
        ]);
    }

    // ✅ Create new employment type
    public function createEmploymentType(Request $request)
    {
        $validated = $request->validate([
            'type_name' => 'required|string|max:100|unique:employment_types,type_name',
            'description' => 'nullable|string',
        ]);

        $type = EmploymentType::create($validated);

        return response()->json([
            'isSuccess' => true,
            'message' => 'Employment type created successfully.',
            'employment_types' => $type
        ], 201);
    }

    // ✅ Get a single employment type
    public function getEmploymentType($id)
    {
        $type = EmploymentType::find($id);

        if (!$type) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Employment type not found.'
            ], 404);
        }

        return response()->json([
            'isSuccess' => true,
            'message' => 'Employment type retrieved successfully.',
            'employment_types' => $type
        ]);
    }

    // ✅ Update employment type
    public function updateEmploymentType(Request $request, $id)
    {
        $type = EmploymentType::find($id);

        if (!$type) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Employment type not found.'
            ], 404);
        }

        $validated = $request->validate([
            'type_name' => 'sometimes|string|max:100|unique:employment_types,type_name,' . $type->id,
            'description' => 'nullable|string',
        ]);

        $type->update($validated);

        return response()->json([
            'isSuccess' => true,
            'message' => 'Employment type updated successfully.',
            'employment_types' => $type
        ]);
    }

    // ✅ Archive employment type instead of deleting
    public function deleteEmploymentType($id)
    {
        $type = EmploymentType::find($id);

        if (!$type) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Employment type not found.'
            ], 404);
        }

        // Archive instead of delete
        $type->is_archived = 1;
        $type->save();

        return response()->json([
            'isSuccess' => true,
            'message' => 'Employment type archived successfully.'
        ]);
    }

    // ✅ Restore archived employment type
    public function restoreEmploymentType($id)
    {
        $type = EmploymentType::find($id);

        if (!$type) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Employment type not found.'
            ], 404);
        }

        $type->is_archived = 0;
        $type->save();

        return response()->json([
            'isSuccess' => true,
            'message' => 'Employment type restored successfully.'
        ]);
    }
}
