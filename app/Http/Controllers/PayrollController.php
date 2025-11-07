<?php

namespace App\Http\Controllers;

use App\Models\{
    PayrollPeriod,
    PayrollRecord,
    PayrollDeduction,
    PayrollAllowance,
    BenefitType,
    AllowanceType,
    Employee,
    Loan
};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Log};

class PayrollController extends Controller
{
    /**
     * Create a new payroll period and generate records for selected employees
     */
    public function createPayrollPeriod(Request $request)
    {
        $request->validate([
            'period_name'        => 'required|string',
            'pay_date'           => 'required|date',
            'cutoff_start_date'  => 'required|date',
            'cutoff_end_date'    => 'required|date',
            'employees'          => 'required|array|min:1',
        ]);

        DB::beginTransaction();

        try {
            // === Create payroll period ===
            $period = PayrollPeriod::create([
                'period_name'        => $request->period_name,
                'pay_date'           => $request->pay_date,
                'cutoff_start_date'  => $request->cutoff_start_date,
                'cutoff_end_date'    => $request->cutoff_end_date,
            ]);

            foreach ($request->employees as $emp) {
                $employee          = Employee::findOrFail($emp['employee_id']);
                $daily             = $employee->base_salary;
                $days              = $emp['days_worked'];
                $overtime          = $emp['overtime_hours'] ?? 0;
                $absences          = $emp['absences'] ?? 0;
                $other_deductions  = $emp['other_deductions'] ?? 0;

                // === BASE GROSS PAY ===
                $gross_base = ($daily * $days) + ($overtime * ($daily / 8)); // raw gross pay (no allowances)

                // === ALLOWANCES ===
                $employeeAllowanceIds = DB::table('employee_allowance')
                    ->where('employee_id', $employee->id)
                    ->pluck('allowance_type_id')
                    ->toArray();

                $allowanceValues = !empty($employeeAllowanceIds)
                    ? AllowanceType::whereIn('id', $employeeAllowanceIds)->get()
                    : collect();

                $total_allowances = $allowanceValues->sum('value');
                $gross_with_allowances = $gross_base + $total_allowances;

                // === BENEFITS (deductions) ===
                $employeeBenefitIds = DB::table('employee_benefit')
                    ->where('employee_id', $employee->id)
                    ->pluck('benefit_type_id')
                    ->toArray();

                $benefitRates = !empty($employeeBenefitIds)
                    ? BenefitType::whereIn('id', $employeeBenefitIds)->pluck('rate', 'benefit_name')
                    : collect();

                $sss        = $benefitRates->has('SSS') ? ($gross_with_allowances * ($benefitRates['SSS'] / 100)) : 0;
                $philhealth = $benefitRates->has('Philhealth') ? ($gross_with_allowances * ($benefitRates['Philhealth'] / 100)) : 0;
                $pagibig    = $benefitRates->has('Pagibig') ? ($gross_with_allowances * ($benefitRates['Pagibig'] / 100)) : 0;

                // === LOANS (deductions) ===
                $activeLoans = Loan::where('employee_id', $employee->id)
                    ->where('status', 'active')
                    ->get();

                $total_loan_deductions = $activeLoans->sum('monthly_amortization');

                // === TOTAL DEDUCTIONS ===
                $total_deductions = $sss + $philhealth + $pagibig + $other_deductions + $total_loan_deductions;

                // === NET PAY ===
                $net = $gross_with_allowances - $total_deductions;

                // === CREATE PAYROLL RECORD ===
                $record = PayrollRecord::create([
                    'payroll_period_id' => $period->id,
                    'employee_id'       => $employee->id,
                    'daily_rate'        => $daily,
                    'days_worked'       => $days,
                    'overtime_hours'    => $overtime,
                    'absences'          => $absences,
                    'other_deductions'  => $other_deductions,
                    'gross_base'        => $gross_base,
                    'gross_pay'         => $gross_with_allowances,
                    'total_deductions'  => $total_deductions,
                    'net_pay'           => $net,
                ]);

                // === RECORD BENEFIT DEDUCTIONS ===
                $deductions = [
                    ['name' => 'SSS',        'amount' => $sss],
                    ['name' => 'Philhealth', 'amount' => $philhealth],
                    ['name' => 'Pagibig',    'amount' => $pagibig],
                ];

                foreach ($deductions as $ded) {
                    if ($ded['amount'] > 0) {
                        $benefitType = BenefitType::where('benefit_name', $ded['name'])->first();
                        if ($benefitType) {
                            PayrollDeduction::create([
                                'payroll_record_id' => $record->id,
                                'benefit_type_id'   => $benefitType->id,
                                'deduction_name'    => $benefitType->benefit_name,
                                'deduction_rate'    => $benefitType->rate,
                                'deduction_amount'  => $ded['amount'],
                                'loan_id'           => null,
                            ]);
                        }
                    }
                }

                // === RECORD LOAN DEDUCTIONS ===
                foreach ($activeLoans as $loan) {
                    $amortization = $loan->monthly_amortization;
                    $newBalance   = max($loan->balance_amount - $amortization, 0);

                    $loan->update([
                        'balance_amount' => $newBalance,
                        'status'         => $newBalance <= 0 ? 'paid' : 'active',
                        'updated_at'     => now(),
                    ]);

                    PayrollDeduction::create([
                        'payroll_record_id' => $record->id,
                        'benefit_type_id'   => null,
                        'loan_id'           => $loan->id,
                        'deduction_name'    => 'Loan Payment',
                        'deduction_rate'    => $loan->interest_rate,
                        'deduction_amount'  => $amortization,
                    ]);
                }

                // === RECORD ALLOWANCES ===
                foreach ($allowanceValues as $allowance) {
                    PayrollAllowance::create([
                        'payroll_record_id' => $record->id,
                        'allowance_type_id' => $allowance->id,
                        'allowance_amount'  => $allowance->value,
                    ]);
                }

                Log::info("Payroll generated for Employee #{$employee->id}: BaseGross={$gross_base}, TotalAllowances={$total_allowances}, GrossWithAllowances={$gross_with_allowances}, Deductions={$total_deductions}, Net={$net}");
            }

            DB::commit();

            return response()->json([
                'isSuccess' => true,
                'message'   => 'Payroll period and employee records created successfully (now includes base gross + total allowances).',
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Payroll generation failed: ' . $e->getMessage());

            return response()->json([
                'isSuccess' => false,
                'message'   => 'Failed to create payroll period.',
                'error'     => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * 👷 Get list of active employees for payroll generation
     */
    public function getEmployees()
    {
        try {
            $employees = Employee::where('is_active', 1)
                ->select('id', 'first_name', 'last_name', 'base_salary', 'position_id', 'department_id')
                ->with([
                    'department:id,department_name',
                    'position:id,position_name',
                ])
                ->orderBy('last_name')
                ->get()
                ->map(fn($emp) => [
                    'employee_id' => $emp->id,
                    'full_name'   => "{$emp->first_name} {$emp->last_name}",
                    'base_salary' => $emp->base_salary,
                    'position'    => $emp->position->position_name ?? null,
                    'department'  => $emp->department->department_name ?? null,
                ]);

            return response()->json([
                'isSuccess' => true,
                'employees' => $employees,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching employees: ' . $e->getMessage());

            return response()->json([
                'isSuccess' => false,
                'message'   => 'Failed to retrieve employee list.',
                'error'     => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 📅 Get all payroll periods with their records
     */
    public function getPayrollPeriods()
    {
        try {
            $periods = PayrollPeriod::with([
                'payrollRecords.employee:id,first_name,last_name,department_id,position_id',
                'payrollRecords.employee.department:id,department_name',
                'payrollRecords.employee.position:id,position_name'
            ])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'isSuccess' => true,
                'payrolls'  => $periods,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'isSuccess' => false,
                'message'   => 'Failed to fetch payroll periods.',
                'error'     => $e->getMessage(),
            ], 500);
        }
    }



    /**
     * 💰 Get payroll details by period
     */
    public function getPayrollDetails(Request $request, $id)
    {
        try {
            $perPage = $request->input('per_page', 5);
            $search  = $request->input('search');

            $query = PayrollRecord::with([
                'employee:id,employee_id,first_name,last_name,email,department_id,position_id,base_salary',
                'employee.department:id,department_name',
                'employee.position:id,position_name',
                'deductions.benefitType:id,benefit_name',
                'deductions.loan.loanType:id,type_name',
                'allowances.allowanceType:id,type_name',
            ])
                ->where('payroll_period_id', $id)
                ->where('is_archived', false);

            if ($search) {
                $query->whereHas('employee', function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('employee_id', 'like', "%{$search}%");
                });
            }

            $payrollDetails = $query->paginate($perPage);

            $summary = [
                'total_gross'      => number_format($payrollDetails->sum('gross_pay'), 2),
                'total_deductions' => number_format($payrollDetails->sum('total_deductions'), 2),
                'total_net'        => number_format($payrollDetails->sum('net_pay'), 2),
            ];

            return response()->json([
                'isSuccess' => true,
                'payrolldetails' => $payrollDetails->items(),
                'pagination' => [
                    'current_page' => $payrollDetails->currentPage(),
                    'per_page'     => $payrollDetails->perPage(),
                    'total'        => $payrollDetails->total(),
                    'last_page'    => $payrollDetails->lastPage(),
                ],
                'summary' => $summary,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching payroll details: ' . $e->getMessage());

            return response()->json([
                'isSuccess' => false,
                'message'   => $e->getMessage(),
            ], 500);
        }
    }


    public function getMyPayrollRecords(Request $request)
    {
        try {
            $user = auth()->user();

            if (!$user || !$user->employee) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'Unauthorized or employee record not found.',
                ], 403);
            }

            $perPage = $request->input('per_page', 10);
            $search  = $request->input('search');

            $query = PayrollRecord::with([
                'payrollPeriod:id,period_name,start_date,end_date',
                'allowances.allowanceType:id,type_name',
                'deductions.benefitType:id,benefit_name',
                'deductions.loan.loanType:id,type_name',
            ])
                ->where('employee_id', $user->employee->id)
                ->where('is_archived', false)
                ->orderByDesc('created_at');

            if ($search) {
                $query->whereHas('payrollPeriod', function ($q) use ($search) {
                    $q->where('period_name', 'like', "%{$search}%");
                });
            }

            $records = $query->paginate($perPage);

            // Format the response for the frontend
            $data = $records->map(function ($record) {
                $allowances = $record->allowances->map(fn($a) => [
                    'allowance_type' => $a->allowanceType->type_name ?? 'Other Allowance',
                    'allowance_amount' => number_format($a->allowance_amount, 2),
                ]);

                $deductions = $record->deductions->map(function ($ded) {
                    if ($ded->loan_id) {
                        return [
                            'deduction_type' => 'Loan Payment',
                            'loan_name' => $ded->loan->loanType->type_name ?? 'Loan',
                            'deduction_amount' => number_format($ded->deduction_amount, 2),
                        ];
                    } elseif ($ded->benefit_type_id) {
                        return [
                            'deduction_type' => $ded->benefitType->benefit_name ?? 'Other Deduction',
                            'deduction_amount' => number_format($ded->deduction_amount, 2),
                        ];
                    }
                    return [
                        'deduction_type' => $ded->deduction_name ?? 'Other Deduction',
                        'deduction_amount' => number_format($ded->deduction_amount, 2),
                    ];
                });

                return [
                    'record_id'        => $record->id,
                    'period'           => $record->payrollPeriod->period_name ?? 'N/A',
                    'period_range'     => ($record->payrollPeriod->start_date ?? '') . ' - ' . ($record->payrollPeriod->end_date ?? ''),
                    'gross_pay'        => number_format($record->gross_pay, 2),
                    'total_deductions' => number_format($record->total_deductions, 2),
                    'net_pay'          => number_format($record->net_pay, 2),
                    'generated_at'     => $record->created_at->format('F d, Y'),
                    'allowances'       => $allowances,
                    'deductions'       => $deductions,
                ];
            });

            return response()->json([
                'isSuccess' => true,
                'message' => 'Employee payroll records retrieved successfully.',
                'data' => $data,
                'pagination' => [
                    'total' => $records->total(),
                    'per_page' => $records->perPage(),
                    'current_page' => $records->currentPage(),
                    'last_page' => $records->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching employee payroll records: ' . $e->getMessage());

            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to fetch payroll records.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * 🧾 Get individual employee payslip
     */
    public function getPayslip($recordId)
    {
        try {
            $record = PayrollRecord::with([
                'employee',
                'deductions.benefitType',
                'deductions.loan.loanType',
                'allowances.allowanceType',
                'payrollPeriod'
            ])->findOrFail($recordId);

            $allowances = $record->allowances->map(fn($a) => [
                'allowance_type'   => $a->allowanceType->type_name ?? 'Other Allowance',
                'allowance_amount' => number_format($a->allowance_amount, 2),
            ]);

            $deductions = $record->deductions->map(function ($ded) {
                if ($ded->loan_id) {
                    return [
                        'deduction_type'   => 'Loan Payment',
                        'loan_name'        => $ded->loan->loanType->type_name ?? 'Loan',
                        'deduction_amount' => number_format($ded->deduction_amount, 2),
                    ];
                } elseif ($ded->benefit_type_id) {
                    return [
                        'deduction_type'   => $ded->benefitType->benefit_name ?? 'Other Deduction',
                        'deduction_amount' => number_format($ded->deduction_amount, 2),
                    ];
                }
                return [
                    'deduction_type'   => $ded->deduction_name ?? 'Other Deduction',
                    'deduction_amount' => number_format($ded->deduction_amount, 2),
                ];
            });

            return response()->json([
                'isSuccess' => true,
                'payslip'   => [
                    'employee_name'     => "{$record->employee->first_name} {$record->employee->last_name}",
                    'period'            => $record->payrollPeriod->period_name,
                    'daily_rate'        => number_format($record->daily_rate, 2),
                    'days_worked'       => number_format($record->days_worked, 2),
                    'gross_base'       => number_format($record->gross_base, 2),
                    'gross_pay'         => number_format($record->gross_pay, 2),
                    'allowances'        => $allowances,
                    'total_allowances'  => number_format($record->allowances->sum('allowance_amount'), 2),
                    'deductions'        => $deductions,
                    'total_deductions'  => number_format($record->total_deductions, 2),
                    'net_pay'           => number_format($record->net_pay, 2),
                    'generated_at'      => now()->format('F d, Y h:i A'),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error generating payslip: ' . $e->getMessage());

            return response()->json([
                'isSuccess' => false,
                'message'   => 'Failed to generate payslip.',
                'error'     => $e->getMessage(),
            ], 500);
        }
    }



    public function getMyPayslips(Request $request)
    {
        try {
            $user = auth()->user();

            // Ensure only employees can use this
            if (!$user || !$user->employee) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'Unauthorized or employee record not found.',
                ], 403);
            }

            $perPage = $request->get('per_page', 10);

            $records = PayrollRecord::with([
                'payrollPeriod',
                'allowances.allowanceType',
                'deductions.benefitType',
                'deductions.loan.loanType',
            ])
                ->where('employee_id', $user->employee->id)
                ->orderByDesc('created_at')
                ->paginate($perPage);

            if ($records->isEmpty()) {
                return response()->json([
                    'isSuccess' => true,
                    'message' => 'No payslips found for this employee.',
                    'data' => [],
                ]);
            }

            $data = $records->map(function ($record) {
                $allowances = $record->allowances->map(fn($a) => [
                    'allowance_type' => $a->allowanceType->type_name ?? 'Other Allowance',
                    'allowance_amount' => number_format($a->allowance_amount, 2),
                ]);

                $deductions = $record->deductions->map(function ($ded) {
                    if ($ded->loan_id) {
                        return [
                            'deduction_type' => 'Loan Payment',
                            'loan_name' => $ded->loan->loanType->type_name ?? 'Loan',
                            'deduction_amount' => number_format($ded->deduction_amount, 2),
                        ];
                    } elseif ($ded->benefit_type_id) {
                        return [
                            'deduction_type' => $ded->benefitType->benefit_name ?? 'Other Deduction',
                            'deduction_amount' => number_format($ded->deduction_amount, 2),
                        ];
                    }
                    return [
                        'deduction_type' => $ded->deduction_name ?? 'Other Deduction',
                        'deduction_amount' => number_format($ded->deduction_amount, 2),
                    ];
                });

                return [
                    'record_id' => $record->id,
                    'period' => $record->payrollPeriod->period_name ?? 'N/A',
                    'gross_pay' => number_format($record->gross_pay, 2),
                    'total_deductions' => number_format($record->total_deductions, 2),
                    'net_pay' => number_format($record->net_pay, 2),
                    'generated_at' => $record->created_at->format('F d, Y'),
                    'allowances' => $allowances,
                    'deductions' => $deductions,
                ];
            });

            return response()->json([
                'isSuccess' => true,
                'message' => 'Payslips retrieved successfully.',
                'data' => $data,
                'pagination' => [
                    'total' => $records->total(),
                    'per_page' => $records->perPage(),
                    'current_page' => $records->currentPage(),
                    'last_page' => $records->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching employee payslips: ' . $e->getMessage());

            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to fetch payslips.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * ✅ Mark payroll period as processed
     */
    public function processPayroll($id)
    {
        try {
            $payroll = PayrollPeriod::findOrFail($id);
            $payroll->update(['status' => 'processed']);

            return response()->json([
                'isSuccess' => true,
                'message'   => 'Payroll period marked as processed successfully.',
                'data'      => $payroll,
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating payroll status: ' . $e->getMessage());

            return response()->json([
                'isSuccess' => false,
                'message'   => 'Failed to update payroll status.',
                'error'     => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * 📊 Get payroll summary stats
     */
    public function getPayrollSummary()
    {
        try {
            $summary = [
                'total_periods'   => DB::table('payroll_periods')->count(),
                'processed'       => DB::table('payroll_periods')->where('status', 'processed')->count(),
                'drafts'          => DB::table('payroll_periods')->where('status', 'draft')->count(),
                'active_employees' => DB::table('employees')->where('is_active', 1)->count(),
            ];

            return response()->json([
                'isSuccess' => true,
                'data'      => $summary,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'isSuccess' => false,
                'message'   => 'Failed to load payroll summary.',
                'error'     => $e->getMessage(),
            ], 500);
        }
    }
}
