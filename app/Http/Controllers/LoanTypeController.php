<?php

namespace App\Http\Controllers;

use App\Models\LoanType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LoanTypeController extends Controller
{
    /**
     * Get all loan types (with optional search and pagination)
     */
    public function getLoanTypes(Request $request)
    {
        $query = LoanType::where('is_archived', false);

        if ($request->has('search') && $request->search !== '') {
            $search = $request->search;
            $query->where('type_name', 'like', "%{$search}%");
        }

        $perPage = $request->get('per_page', 10);
        $loanTypes = $query->paginate($perPage);

        return response()->json([
            'isSuccess' => true,
            'loan_types' => $loanTypes->items(),
            'pagination' => [
                'current_page' => $loanTypes->currentPage(),
                'per_page' => $loanTypes->perPage(),
                'total' => $loanTypes->total(),
                'last_page' => $loanTypes->lastPage(),
            ],
        ]);
    }


    public function getLoanTypeById($id)
    {
        $loanType = LoanType::find($id);

        if (!$loanType) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Loan type not found.'
            ], 404);
        }

        return response()->json([
            'isSuccess' => true,
            'message' => 'Loan type retrieved successfully.',
            'data' => $loanType
        ]);
    }

    /**
     * Create a new loan type
     */
    public function createLoanType(Request $request)
    {
        $validated = $request->validate([
            'type_name' => 'required|string|max:100|unique:loan_types,type_name',
            'description' => 'nullable|string',
            'amount' => 'nullable|numeric|min:0',
            'amount_limit' => 'nullable|numeric|min:0',
        ]);

        $loanType = LoanType::create($validated);

        Log::info('Loan Type Created', ['loan_type' => $loanType]);

        return response()->json([
            'isSuccess' => true,
            'message' => 'Loan type created successfully.',
            'data' => $loanType,
        ], 201);
    }

    /**
     * Update an existing loan type
     */
    public function updateLoanType(Request $request, $id)
    {
        $loanType = LoanType::findOrFail($id);

        $validated = $request->validate([
            'type_name' => 'required|string|max:100|unique:loan_types,type_name,' . $id,
            'description' => 'nullable|string',
            'amount' => 'nullable|numeric|min:0',
            'amount_limit' => 'nullable|numeric|min:0',
        ]);

        $loanType->update($validated);

        Log::info('Loan Type Updated', ['loan_type' => $loanType]);

        return response()->json([
            'isSuccess' => true,
            'message' => 'Loan type updated successfully.',
            'data' => $loanType,
        ]);
    }

    /**
     * Archive a loan type (soft delete)
     */
    public function archiveLoanType($id)
    {
        $loanType = LoanType::findOrFail($id);
        $loanType->update(['is_archived' => true]);

        Log::info('Loan Type Archived', ['loan_type' => $loanType]);

        return response()->json([
            'isSuccess' => true,
            'message' => 'Loan type archived successfully.',
        ]);
    }
}
