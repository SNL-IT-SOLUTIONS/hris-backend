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
    Loan,
    ThirteenthMonth
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
            'period_name'       => 'required|string',
            'pay_date'          => 'required|date',
            'cutoff_start_date' => 'required|date',
            'cutoff_end_date'   => 'required|date|after_or_equal:cutoff_start_date',
            'employees'         => 'required|array|min:1',
            'employees.*.employee_id'    => 'required|exists:employees,id',
            'employees.*.days_worked'    => 'required|numeric|min:0',
            'employees.*.overtime_hours' => 'nullable|numeric|min:0',
            'employees.*.absences'       => 'nullable|numeric|min:0',
        ]);

        DB::beginTransaction();

        try {
            // Create Period
            $period = PayrollPeriod::create([
                'period_name'       => $request->period_name,
                'pay_date'          => $request->pay_date,
                'cutoff_start_date' => $request->cutoff_start_date,
                'cutoff_end_date'   => $request->cutoff_end_date,
            ]);

            foreach ($request->employees as $emp) {

                $employee  = Employee::findOrFail($emp['employee_id']);
                $daily     = $employee->base_salary;
                $days      = $emp['days_worked'];
                $overtime  = $emp['overtime_hours'] ?? 0;
                $absences  = $emp['absences'] ?? 0;

                $hourlyRate = $daily / 8;
                // ================================
                //  NIGHT DIFFERENTIAL CALCULATION 
                // ================================
                $nightHours  = $employee->night_hours ?? 0;  // e.g. 7 hours
                $nightRate   = $employee->night_rate ?? 10;  // percent (10%)
                $nightDiffPerDay = $hourlyRate * ($nightRate / 100) * $nightHours;
                $totalNightDiff  = $nightDiffPerDay * $days;
                // ================================

                // OT Calculation
                $overtimeRate = $hourlyRate * 1.25;

                // Add ND to gross_base
                $gross_base = ($daily * $days)
                    + ($overtime * $overtimeRate);

                // Allowances
                $employeeAllowances = DB::table('employee_allowance')
                    ->join('allowance_types', 'employee_allowance.allowance_type_id', '=', 'allowance_types.id')
                    ->where('employee_allowance.employee_id', $employee->id)
                    ->select(
                        'employee_allowance.allowance_type_id',
                        'allowance_types.type_name as allowance_name',
                        'employee_allowance.amount as allowance_amount'
                    )
                    ->get();

                $total_allowances = $employeeAllowances->sum('allowance_amount') / 2;
                $gross_with_allowances = $gross_base + $total_allowances + $totalNightDiff;

                // Benefits
                $employeeBenefits = DB::table('employee_benefit')
                    ->join('benefit_types', 'employee_benefit.benefit_type_id', '=', 'benefit_types.id')
                    ->where('employee_benefit.employee_id', $employee->id)
                    ->select(
                        'employee_benefit.benefit_type_id',
                        'benefit_types.benefit_name',
                        'employee_benefit.amount'
                    )
                    ->get();

                $benefitDeductions = $employeeBenefits->map(fn($benefit) => [
                    'benefit_type_id' => $benefit->benefit_type_id,
                    'benefit_name'    => $benefit->benefit_name,
                    'amount'          => $benefit->amount ?? 0,
                ])->toArray();

                $total_benefit_deductions = collect($benefitDeductions)->sum('amount') / 2;

                // Loans
                $activeLoans = Loan::where('employee_id', $employee->id)
                    ->where('status', 'active')
                    ->get();

                $total_loan_deductions = $activeLoans->sum('monthly_amortization') / 2;

                // Final deductions
                $total_deductions = $total_benefit_deductions + $total_loan_deductions;

                // Net Pay
                $net = $gross_with_allowances - $total_deductions;

                // ================================
                // CREATE PAYROLL RECORD 
                // ================================
                $record = PayrollRecord::create([
                    'payroll_period_id'     => $period->id,
                    'employee_id'           => $employee->id,
                    'daily_rate'            => $daily,
                    'days_worked'           => $days,
                    'overtime_hours'        => $overtime,
                    'absences'              => $absences,
                    'night_diff_pay'        => $totalNightDiff,
                    'gross_base'            => $gross_base,
                    'gross_pay'             => $gross_with_allowances,
                    'total_allowances'      => $total_allowances,
                    'total_loan_deductions' => $total_loan_deductions,
                    'total_deductions'      => $total_deductions,
                    'net_pay'               => $net,
                ]);
                // ================================

                // Benefit Deduction Records
                foreach ($benefitDeductions as $benefit) {
                    PayrollDeduction::create([
                        'payroll_record_id' => $record->id,
                        'benefit_type_id'   => $benefit['benefit_type_id'],
                        'deduction_name'    => $benefit['benefit_name'],
                        'deduction_amount'  => $benefit['amount'] / 2,
                    ]);
                }

                // Loan Deductions
                foreach ($activeLoans as $loan) {
                    $amortization = $loan->monthly_amortization / 2;

                    if ($amortization > 0 && $loan->balance_amount > 0) {
                        $newBalance = max($loan->balance_amount - $amortization, 0);

                        $loan->update([
                            'balance_amount' => $newBalance,
                            'status'         => $newBalance <= 0 ? 'paid' : 'active',
                            'updated_at'     => now(),
                        ]);

                        PayrollDeduction::create([
                            'payroll_record_id' => $record->id,
                            'loan_id'           => $loan->id,
                            'deduction_name'    => 'Loan Payment',
                            'deduction_rate'    => $loan->interest_rate,
                            'deduction_amount'  => $amortization,
                        ]);
                    }
                }

                // Allowance Records
                foreach ($employeeAllowances as $allowance) {
                    PayrollAllowance::create([
                        'payroll_record_id' => $record->id,
                        'allowance_type_id' => $allowance->allowance_type_id,
                        'allowance_amount'  => ($allowance->allowance_amount ?? 0) / 2,
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'isSuccess' => true,
                'message'   => 'Payroll period and employee records created successfully.',
                'data'      => [
                    'period'        => $period,
                    'records_count' => count($request->employees),
                ]
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






    public function updatePayrollPeriod(Request $request, $id)
    {
        $request->validate([
            'period_name'       => 'required|string',
            'pay_date'          => 'required|date',
            'cutoff_start_date' => 'required|date',
            'cutoff_end_date'   => 'required|date|after_or_equal:cutoff_start_date',
        ]);

        $period = PayrollPeriod::find($id);


        if (!$period) {
            return response()->json([
                'isSuccess' => false,
                'message'   => 'Payroll period not found.',
            ], 404);
        }

        $period->update([
            'period_name'       => $request->period_name,
            'pay_date'          => $request->pay_date,
            'cutoff_start_date' => $request->cutoff_start_date,
            'cutoff_end_date'   => $request->cutoff_end_date,
        ]);

        return response()->json([
            'isSuccess' => true,
            'message'   => 'Payroll period updated successfully.',
            'data'      => $period,
        ]);
    }

    public function archivePayrollPeriod($id)
    {
        $period = PayrollPeriod::find($id);

        if (!$period) {
            return response()->json([
                'isSuccess' => false,
                'message'   => 'Payroll period not found.',
            ], 404);
        }

        // Archive the payroll period
        $period->update(['is_archived' => true]);

        // Archive all payroll records under this period
        PayrollRecord::where('payroll_period_id', $id)
            ->update(['is_archived' => true]);

        return response()->json([
            'isSuccess' => true,
            'message'   => 'Payroll period and related records archived successfully.',
            'data'      => $period->load('payrollRecords:id,payroll_period_id,is_archived'),
        ]);
    }






    /**
     * Get list of active employees for payroll generation
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
                ->where('is_archived', false)

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
     * Get all payroll periods with their records
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
                ->where('is_archived', false)

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
     * Get payroll periods for the logged-in user
     */
    public function getMyPayrollPeriods()
    {
        try {
            $user = auth()->user();

            if (!$user) {
                return response()->json([
                    'isSuccess' => false,
                    'message'   => 'Unauthorized access.',
                ], 401);
            }

            //  Fetch periods that have payroll records for the logged-in user
            $periods = PayrollPeriod::whereHas('payrollRecords', function ($query) use ($user) {
                $query->where('employee_id', $user->id)
                    ->where('is_archived', false);
            })
                ->with([
                    'payrollRecords' => function ($query) use ($user) {
                        $query->where('employee_id', $user->id)
                            ->select('id', 'payroll_period_id', 'employee_id', 'gross_pay', 'total_deductions', 'net_pay');
                    },
                    'payrollRecords.employee:id,first_name,last_name,department_id,position_id',
                    'payrollRecords.employee.department:id,department_name',
                    'payrollRecords.employee.position:id,position_name'
                ])
                ->orderByDesc('created_at')
                ->get();

            return response()->json([
                'isSuccess' => true,
                'periods'   => $periods,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching user payroll periods: ' . $e->getMessage());

            return response()->json([
                'isSuccess' => false,
                'message'   => 'Failed to fetch payroll periods.',
                'error'     => $e->getMessage(),
            ], 500);
        }
    }




    /**
     *  Get payroll details by period
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

            // ✅ Clone query for totals
            $totalsQuery = clone $query;

            // 🧮 Compute totals for all records (not just current page)
            $summary = [
                'total_gross'      => number_format($totalsQuery->sum('gross_pay'), 2),
                'total_deductions' => number_format($totalsQuery->sum('total_deductions'), 2),
                'total_net'        => number_format($totalsQuery->sum('net_pay'), 2),
            ];

            // 📄 Now paginate for display
            $payrollDetails = $query->paginate($perPage);

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


    public function getMyPayrollDetails(Request $request, $id)
    {
        try {
            $user = auth()->user();

            $perPage = $request->input('per_page', 5);
            $search  = $request->input('search');

            $query = PayrollRecord::with([
                'employee:id,employee_id,first_name,last_name,email,department_id,position_id,base_salary',
                'employee.department:id,department_name',
                'employee.position:id,position_name',
                'deductions.benefitType:id,benefit_name',
                'deductions.loan.loanType:id,type_name',
                'allowances.allowanceType:id,type_name',
                'payrollPeriod:id,period_name,cutoff_start_date,cutoff_end_date,pay_date'
            ])
                ->where('payroll_period_id', $id)
                ->where('employee_id', $user->id)

                ->where('is_archived', false);

            if ($search) {
                $query->whereHas('payrollPeriod', function ($q) use ($search) {
                    $q->where('period_name', 'like', "%{$search}%");
                });
            }

            // ✅ Clone query for totals
            $totalsQuery = clone $query;

            // 🧮 Compute summary for user’s record(s)
            $summary = [
                'total_gross'      => number_format($totalsQuery->sum('gross_pay'), 2),
                'total_deductions' => number_format($totalsQuery->sum('total_deductions'), 2),
                'total_net'        => number_format($totalsQuery->sum('net_pay'), 2),
            ];

            // 📄 Paginate (usually just one record per period per user, but still for consistency)
            $details = $query->paginate($perPage);

            return response()->json([
                'isSuccess' => true,
                'payrolldetails' => $details->items(),
                'pagination' => [
                    'current_page' => $details->currentPage(),
                    'per_page'     => $details->perPage(),
                    'total'        => $details->total(),
                    'last_page'    => $details->lastPage(),
                ],
                'summary' => $summary,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching user payroll details: ' . $e->getMessage());

            return response()->json([
                'isSuccess' => false,
                'message'   => 'Failed to fetch payroll details.',
                'error'     => $e->getMessage(),
            ], 500);
        }
    }



    public function getMyPayrollRecords(Request $request)
    {
        try {
            $user = auth()->user();

            if (!$user) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'Unauthorized.',
                ], 403);
            }

            $employeeId = $user->id; // employee primary key


            $perPage = $request->input('per_page', 10);
            $search  = $request->input('search');

            $query = PayrollRecord::with([
                'payrollPeriod:id,period_name,pay_date,cutoff_start_date,cutoff_end_date',

                'allowances.allowanceType:id,type_name',
                'deductions.benefitType:id,benefit_name',
                'deductions.loan.loanType:id,type_name',
            ])
                ->where('employee_id', $employeeId)
                ->where('is_archived', false)
                ->orderByDesc('created_at');


            if ($search) {
                $query->whereHas('payrollPeriod', function ($q) use ($search) {
                    $q->where('period_name', 'like', "%{$search}%");
                });
            }

            $records = $query->paginate($perPage);

            $data = $records->map(function ($record) {
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
                'message'   => 'Employee payroll records retrieved successfully.',
                'data'      => $data,
                'pagination' => [
                    'total'         => $records->total(),
                    'per_page'      => $records->perPage(),
                    'current_page'  => $records->currentPage(),
                    'last_page'     => $records->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching employee payroll records: ' . $e->getMessage());

            return response()->json([
                'isSuccess' => false,
                'message'   => 'Failed to fetch payroll records.',
                'error'     => $e->getMessage(),
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

            ])
                ->where('is_archived', false)
                ->findOrFail($recordId);



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
                    'gross_base'        => number_format($record->gross_base, 2),
                    'gross_pay'         => number_format($record->gross_pay, 2),
                    'base_pay'          => number_format($record->daily_rate * $record->days_worked, 2),
                    'night_diff_pay'   => number_format($record->night_diff_pay, 2),
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



    public function getMyPayslips(Request $request, $recordId)
    {
        try {
            $employee = auth()->user(); // THIS IS THE LOGGED-IN EMPLOYEE

            if (!$employee) {
                return response()->json([
                    'isSuccess' => false,
                    'message'   => 'Unauthorized.',
                ], 403);
            }

            $record = PayrollRecord::with([
                'payrollPeriod',
                'allowances.allowanceType',
                'deductions.benefitType',
                'deductions.loan.loanType',
            ])
                ->where('employee_id', $employee->id)
                ->where('id', $recordId)
                ->where('is_archived', false)

                ->first();

            if (!$record) {
                return response()->json([
                    'isSuccess' => false,
                    'message'   => 'Payslip not found.',
                ], 404);
            }

            // Map allowances
            $allowances = $record->allowances->map(fn($a) => [
                'allowance_type'   => $a->allowanceType->type_name ?? 'Other Allowance',
                'allowance_amount' => number_format($a->allowance_amount, 2),
            ]);

            // Map deductions
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
                    'employee_name'     => "{$employee->first_name} {$employee->last_name}",
                    'period'            => $record->payrollPeriod->period_name ?? 'N/A',
                    'daily_rate'        => number_format($record->daily_rate, 2),
                    'days_worked'       => number_format($record->days_worked, 2),
                    'gross_base'        => number_format($record->gross_base, 2),
                    'gross_pay'         => number_format($record->gross_pay, 2),
                    'allowances'        => $allowances,
                    'total_allowances'  => number_format($record->allowances->sum('allowance_amount'), 2),
                    'deductions'        => $deductions,
                    'total_deductions'  => number_format($record->total_deductions, 2),
                    'net_pay'           => number_format($record->net_pay, 2),
                    'generated_at'      => $record->created_at->format('F d, Y h:i A'),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching employee payslip: ' . $e->getMessage());

            return response()->json([
                'isSuccess' => false,
                'message'   => 'Failed to fetch payslip.',
                'error'     => $e->getMessage(),
            ], 500);
        }
    }







    /**
     * Mark payroll period as processed
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
     *  Get payroll summary stats
     */
    public function getPayrollSummary()
    {
        try {
            $summary = [
                'total_periods'    => DB::table('payroll_periods')->where('is_archived', false)->count(),
                'processed'        => DB::table('payroll_periods')->where('status', 'processed')->where('is_archived', false)->count(),
                'drafts'           => DB::table('payroll_periods')->where('status', 'draft')->where('is_archived', false)->count(),
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

    public function generateThirteenthMonthPay(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'employees' => 'required|array',
            'employees.*.employee_id' => 'required|integer|exists:employees,id',
        ]);

        foreach ($request->employees as $empData) {
            $employeeId = $empData['employee_id'];

            // Get total salary earned within the date range
            $totalBasicSalary = PayrollRecord::where('employee_id', $employeeId)
                ->whereBetween('created_at', [$request->start_date, $request->end_date])
                ->sum('gross_base');

            // Compute 13th month pay
            $thirteenthMonthPay = $totalBasicSalary / 12;

            // Save to table
            ThirteenthMonth::updateOrCreate(
                [
                    'employee_id' => $employeeId,
                    'start_date' => $request->start_date,
                    'end_date' => $request->end_date,
                ],
                [
                    'amount' => $thirteenthMonthPay,
                ]
            );
        }

        return response()->json(['message' => '13th month pay generated for selected employees']);
    }

    public function getThirteenthMonthPays(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 10);
            $search = $request->input('search');

            $query = ThirteenthMonth::with('employee:id,first_name,last_name,employee_id')
                ->where('is_archived', false)
                ->orderByDesc('created_at');

            if ($search) {
                $query->whereHas('employee', function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('employee_id', 'like', "%{$search}%");
                });
            }

            $thirteenthMonths = $query->paginate($perPage);

            return response()->json([
                'isSuccess' => true,
                'data' => $thirteenthMonths->items(),
                'pagination' => [
                    'total' => $thirteenthMonths->total(),
                    'per_page' => $thirteenthMonths->perPage(),
                    'current_page' => $thirteenthMonths->currentPage(),
                    'last_page' => $thirteenthMonths->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching 13th month pays: ' . $e->getMessage());

            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to fetch 13th month pays.',
                'error' => $e->getMessage(),
            ], 500);
        }
}
