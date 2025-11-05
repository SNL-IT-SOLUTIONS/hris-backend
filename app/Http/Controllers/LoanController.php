<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\LoanType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LoanController extends Controller
{
    /**
     * Display a paginated list of loans with related loan type & employee info.
     */
    public function getLoans(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 10);
            $search = $request->get('search');
            $status = $request->get('status');

            $query = Loan::with(['loanType', 'employee']);

            if ($search) {
                $query->whereHas('loanType', function ($q) use ($search) {
                    $q->where('type_name', 'like', "%$search%");
                });
            }

            if ($status) {
                $query->where('status', $status);
            }

            $loans = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $loans,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching loans: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch loans.',
            ], 500);
        }
    }

    /**
     * Create a new loan record.
     */
    public function createLoan(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'loan_type_id' => 'required|exists:loan_types,id',
            'principal_amount' => 'required|numeric|min:0',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'remarks' => 'nullable|string',
        ]);

        try {
            $loanType = LoanType::findOrFail($validated['loan_type_id']);

            $loan = Loan::create([
                'employee_id' => $validated['employee_id'],
                'loan_type_id' => $loanType->id,
                'principal_amount' => $validated['principal_amount'],
                'balance_amount' => $validated['principal_amount'],
                'monthly_amortization' => $loanType->amount ?? 0.00,
                'interest_rate' => $loanType->interest ?? 0.00,
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'] ?? null,
                'status' => 'active',
                'remarks' => $validated['remarks'] ?? null,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Loan created successfully.',
                'data' => $loan,
            ]);
        } catch (\Exception $e) {
            Log::error('Error creating loan: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create loan.',
            ], 500);
        }
    }

    /**
     * Update an existing loan record.
     */
    public function updateLoan(Request $request, $id)
    {
        $validated = $request->validate([
            'principal_amount' => 'nullable|numeric|min:0',
            'balance_amount' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:active,paid,defaulted,cancelled',
            'remarks' => 'nullable|string',
        ]);

        try {
            $loan = Loan::findOrFail($id);
            $loan->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Loan updated successfully.',
                'data' => $loan,
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating loan: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update loan.',
            ], 500);
        }
    }

    public function approveLoan($id)
    {
        try {
            $loan = Loan::findOrFail($id);
            $loan->update(['status' => 'active']);

            return response()->json([
                'success' => true,
                'message' => 'Loan approved successfully.',
            ]);
        } catch (\Exception $e) {
            Log::error('Error approving loan: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to approve loan.',
            ], 500);
        }
    }

    /**
     * Cancel (soft delete) a loan.
     */
    public function cancelLoan($id)
    {
        try {
            $loan = Loan::findOrFail($id);
            $loan->update(['status' => 'cancelled']);

            return response()->json([
                'success' => true,
                'message' => 'Loan cancelled successfully.',
            ]);
        } catch (\Exception $e) {
            Log::error('Error cancelling loan: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel loan.',
            ], 500);
        }
    }
}
