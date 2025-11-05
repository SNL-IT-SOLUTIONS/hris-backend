<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\LoanType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

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
            'end_date' => 'required|date|after:today',
            'remarks' => 'nullable|string',
        ]);

        try {
            $loanType = LoanType::findOrFail($validated['loan_type_id']);

            // 🔒 Check amount limit
            if (!is_null($loanType->amount_limit) && $validated['principal_amount'] > $loanType->amount_limit) {
                return response()->json([
                    'success' => false,
                    'message' => "The principal amount exceeds the limit of {$loanType->amount_limit}.",
                ], 422);
            }

            // Use today's date as start_date
            $startDate = now();
            $endDate = Carbon::parse($validated['end_date']);

            //  Calculate the number of months between now and end_date
            $months = max(1, $startDate->diffInMonths($endDate) + 1);


            //  Calculate monthly interest and amortization
            $interestRate = $loanType->interest ?? 0;
            $principal = $validated['principal_amount'];

            //  Total with interest (monthly simple interest, not compounded)
            $totalWithInterest = $principal * (1 + ($interestRate / 100) * $months);

            // 🧾 Monthly amortization (divide evenly by number of months)
            $monthlyAmortization = $totalWithInterest / $months;

            //  Create loan record
            $loan = Loan::create([
                'employee_id' => $validated['employee_id'],
                'loan_type_id' => $loanType->id,
                'principal_amount' => $principal,
                'balance_amount' => round($totalWithInterest, 2),
                'monthly_amortization' => round($monthlyAmortization, 2),
                'interest_rate' => $interestRate,
                'start_date' => $startDate,
                'end_date' => $validated['end_date'],
                'status' => 'pending',
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
                'error' => $e->getMessage(),
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
