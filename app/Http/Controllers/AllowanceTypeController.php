<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Allowances;
use App\Models\AllowanceType;
use Illuminate\Support\Facades\Validator;

class AllowanceTypeController extends Controller
{
    /**
     * Get all allowance types (with optional search + pagination)
     */
    public function getAllowanceTypes(Request $request)
    {
        $query = AllowanceType::query();

        //  Search filter
        if ($request->has('search') && $request->search != '') {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('type_name', 'LIKE', "%$search%")
                    ->orWhere('description', 'LIKE', "%$search%");
            });
        }

        //  Pagination
        $perPage = $request->input('per_page', 10);
        $allowances = $query->orderBy('id', 'desc')->paginate($perPage);

        if ($allowances->isEmpty()) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'No allowance types found.'
            ], 404);
        }

        return response()->json([
            'isSuccess' => true,
            'message' => 'Allowance types retrieved successfully.',
            'allowance_types' => $allowances->items(),
            'pagination' => [
                'current_page' => $allowances->currentPage(),
                'per_page' => $allowances->perPage(),
                'total' => $allowances->total(),
                'last_page' => $allowances->lastPage(),
            ]
        ]);
    }

    /**
     * Create a new allowance type
     */
    public function createAllowanceType(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'type_name' => 'required|string|max:100|unique:allowance_types,type_name',
            'description' => 'nullable|string',
            'value' => 'required|integer|min:0'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors()
            ], 422);
        }

        $allowance = AllowanceType::create($validator->validated());

        return response()->json([
            'isSuccess' => true,
            'message' => 'Allowance type created successfully.',
            'allowance_type' => $allowance
        ], 201);
    }

    /**
     *  Update an existing allowance type
     */
    public function updateAllowanceType(Request $request, $id)
    {
        $allowance = AllowanceType::find($id);

        if (!$allowance) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Allowance type not found.'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'type_name' => 'required|string|max:100|unique:allowance_types,type_name,' . $allowance->id,
            'description' => 'nullable|string',
            'value' => 'required|integer|min:0'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors()
            ], 422);
        }

        $allowance->update($validator->validated());

        return response()->json([
            'isSuccess' => true,
            'message' => 'Allowance type updated successfully.',
            'allowance_type' => $allowance
        ]);
    }

    /**
     *  Delete an allowance type
     */
    public function deleteAllowanceType($id)
    {
        $allowance = Allowances::find($id);

        if (!$allowance) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Allowance type not found.'
            ], 404);
        }

        // Soft delete: mark as archived
        $allowance->is_archived = true;
        $allowance->save();

        return response()->json([
            'isSuccess' => true,
            'message' => 'Allowance type archived successfully.'
        ]);
    }


    /**
     * Get allowance type by ID
     */
    public function getAllowanceTypeById($id)
    {
        $allowance = Allowances::find($id);

        if (!$allowance) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Allowance type not found.'
            ], 404);
        }

        return response()->json([
            'isSuccess' => true,
            'message' => 'Allowance type retrieved successfully.',
            'allowance_type' => $allowance
        ]);
    }
}
