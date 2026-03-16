<?php

namespace App\Http\Controllers;

use App\Models\BenefitType;
use Illuminate\Http\Request;


class BenefitTypeController extends Controller
{
    // Get all benefit types (excluding archived)
    public function getBenefitTypes()
    {
        $benefits = BenefitType::where('is_archived', 0)->get();

        return response()->json([
            'isSuccess' => true,
            'message' => 'Benefit types retrieved successfully.',
            'benefit_types' => $benefits
        ]);
    }

    // Create new benefit type
    public function createBenefitType(Request $request)
    {
        $validated = $request->validate([
            'benefit_name' => 'required|string|max:150|unique:benefit_types,benefit_name',
            'category' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'deduction' => 'nullable|numeric',

            'is_active' => 'boolean',
        ]);

        $benefit = BenefitType::create($validated);

        return response()->json([
            'isSuccess' => true,
            'message' => 'Benefit type created successfully.',
            'benefit_types' => $benefit
        ], 201);
    }

    // Get a single benefit type
    public function getBenefitType($id)
    {
        $benefit = BenefitType::find($id);

        if (!$benefit) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Benefit type not found.'
            ], 404);
        }

        return response()->json([
            'isSuccess' => true,
            'message' => 'Benefit type retrieved successfully.',
            'benefit_types' => $benefit
        ]);
    }

    // Update benefit type
    public function updateBenefitType(Request $request, $id)
    {
        $benefit = BenefitType::find($id);

        if (!$benefit) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Benefit type not found.'
            ], 404);
        }

        $validated = $request->validate([
            'benefit_name' => 'sometimes|string|max:150|unique:benefit_types,benefit_name,' . $benefit->id,
            'category' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'deduction' => 'nullable|numeric',
            'is_active' => 'boolean',
        ]);

        $benefit->update($validated);

        return response()->json([
            'isSuccess' => true,
            'message' => 'Benefit type updated successfully.',
            'benefit_types' => $benefit
        ]);
    }

    // Archive benefit type instead of deleting
    public function deleteBenefitType($id)
    {
        $benefit = BenefitType::find($id);

        if (!$benefit) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Benefit type not found.'
            ], 404);
        }

        // Archive instead of delete
        $benefit->is_archived = 1;
        $benefit->save();

        return response()->json([
            'isSuccess' => true,
            'message' => 'Benefit type archived successfully.'
        ]);
    }

    // Optional: Restore archived benefit type
    public function restoreBenefitType($id)
    {
        $benefit = BenefitType::find($id);

        if (!$benefit) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Benefit type not found.'
            ], 404);
        }

        $benefit->is_archived = 0;
        $benefit->save();

        return response()->json([
            'isSuccess' => true,
            'message' => 'Benefit type restored successfully.'
        ]);
    }
}
