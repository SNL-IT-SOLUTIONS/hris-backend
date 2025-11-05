<?php

namespace App\Http\Controllers;

use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\PayrollDeduction;
use App\Models\BenefitType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Employee;
use App\Models\AllowanceType;
use App\Models\PayrollAllowance;



class PayrollController extends Controller
{
    /**
     * Create a new payroll period and generate records for selected employees
     */
    public function createPayrollPeriod(Request $request)
    {
        $request->validate([
            'period_name' => 'required|string',
            'pay_date' => 'required|date',
            'cutoff_start_date' => 'required|date',
            'cutoff_end_date' => 'required|date',
            'employees' => 'required|array|min:1',
        ]);

        DB::beginTransaction();

        try {
            // create payroll period
            $period = PayrollPeriod::create([
                'period_name' => $request->period_name,
                'pay_date' => $request->pay_date,
                'cutoff_start_date' => $request->cutoff_start_date,
                'cutoff_end_date' => $request->cutoff_end_date,
            ]);

            foreach ($request->employees as $emp) {
                $employee = Employee::findOrFail($emp['employee_id']);
                $daily = $employee->base_salary;
                $days = $emp['days_worked'];
                $overtime = $emp['overtime_hours'] ?? 0;
                $absences = $emp['absences'] ?? 0;
                $other_deductions = $emp['other_deductions'] ?? 0;

                // GROSS PAY
                $gross = ($daily * $days) + ($overtime * ($daily / 8));

                // === BENEFITS (deductions) ===
                $employeeBenefitIds = DB::table('employee_benefit')
                    ->where('employee_id', $employee->id)
                    ->pluck('benefit_type_id')
                    ->toArray();

                $benefitRates = !empty($employeeBenefitIds)
                    ? BenefitType::whereIn('id', $employeeBenefitIds)->get()->pluck('rate', 'benefit_name')
                    : collect();

                $sss = $benefitRates->has('SSS') ? ($gross * ($benefitRates['SSS'] / 100)) : 0;
                $philhealth = $benefitRates->has('Philhealth') ? ($gross * ($benefitRates['Philhealth'] / 100)) : 0;
                $pagibig = $benefitRates->has('Pagibig') ? ($gross * ($benefitRates['Pagibig'] / 100)) : 0;

                $total_deductions = $sss + $philhealth + $pagibig + $other_deductions;

                // === ALLOWANCES ===
                $employeeAllowanceIds = DB::table('employee_allowance')
                    ->where('employee_id', $employee->id)
                    ->pluck('allowance_type_id')
                    ->toArray();

                $allowanceValues = !empty($employeeAllowanceIds)
                    ? AllowanceType::whereIn('id', $employeeAllowanceIds)->get()
                    : collect();

                $total_allowances = $allowanceValues->sum('value');

                // NET PAY
                $net = $gross + $total_allowances - $total_deductions;

                // === CREATE PAYROLL RECORD ===
                $record = PayrollRecord::create([
                    'payroll_period_id' => $period->id,
                    'employee_id' => $employee->id,
                    'daily_rate' => $daily,
                    'days_worked' => $days,
                    'overtime_hours' => $overtime,
                    'absences' => $absences,
                    'other_deductions' => $other_deductions,
                    'gross_pay' => $gross,
                    'total_deductions' => $total_deductions,
                    'net_pay' => $net,
                ]);

                // === SAVE DEDUCTIONS ===
                $deductions = [
                    ['name' => 'SSS', 'amount' => $sss],
                    ['name' => 'Philhealth', 'amount' => $philhealth],
                    ['name' => 'Pagibig', 'amount' => $pagibig],
                ];

                foreach ($deductions as $ded) {
                    if ($ded['amount'] > 0) {
                        $benefitType = BenefitType::where('benefit_name', $ded['name'])->first();
                        if ($benefitType) {
                            PayrollDeduction::create([
                                'payroll_record_id' => $record->id,
                                'benefit_type_id' => $benefitType->id,
                                'deduction_name' => $benefitType->benefit_name,
                                'deduction_rate' => $benefitType->rate,
                                'deduction_amount' => $ded['amount'],
                            ]);
                        }
                    }
                }

                // === SAVE ALLOWANCES ===
                foreach ($allowanceValues as $allowance) {
                    PayrollAllowance::create([
                        'payroll_record_id' => $record->id,
                        'allowance_type_id' => $allowance->id,
                        'allowance_amount' => $allowance->value,
                    ]);
                }

                Log::info("Payroll generated for Employee #{$employee->id}: Gross={$gross}, Allowances={$total_allowances}, Deductions={$total_deductions}, Net={$net}");
            }

            DB::commit();

            return response()->json([
                'isSuccess' => true,
                'message' => 'Payroll period and employee records created successfully.',
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Payroll generation failed: ' . $e->getMessage());

            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to create payroll period.',
                'error' => $e->getMessage(),
            ], 500);
        }
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
                    'position:id,position_name'
                ])
                ->orderBy('last_name')
                ->get()
                ->map(function ($emp) {
                    return [
                        'employee_id' => $emp->id,
                        'full_name' => "{$emp->first_name} {$emp->last_name}",
                        'base_salary' => $emp->base_salary,
                        'position' => $emp->position->position_name ?? null,
                        'department' => $emp->department->department_name ?? null,
                    ];
                });

            return response()->json([
                'isSuccess' => true,
                'employees' => $employees,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching employees: ' . $e->getMessage());

            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to retrieve employee list.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Get all payroll periods with records
     */
    public function getPayrollPeriods()
    {
        $periods = PayrollPeriod::with('payrollRecords.employee')->orderBy('created_at', 'desc')->get();

        return response()->json([
            'isSuccess' => true,
            'payrolls' => $periods,
        ]);
    }

    public function getPayrollSummary()
    {
        $summary = [
            'total_periods' => DB::table('payroll_periods')->count(),
            'processed' => DB::table('payroll_periods')->where('status', 'processed')->count(),
            'drafts' => DB::table('payroll_periods')->where('status', 'draft')->count(),
            'active_employees' => DB::table('employees')->where('is_active', 1)->count(),
        ];

        return response()->json([
            'isSuccess' => true,
            'data' => $summary,
        ]);
    }


    /**
     * View payroll details (with deductions)
     */
    public function getPayrollDetails(Request $request, $periodId)
    {
        // Number of records per page (default: 10)
        $perPage = $request->get('per_page', 5);

        // Paginate records with relationships
        $records = PayrollRecord::with(['employee', 'deductions.benefitType'])
            ->where('payroll_period_id', $periodId)
            ->paginate($perPage);

        // Compute total summary for the entire payroll period
        $allRecords = PayrollRecord::where('payroll_period_id', $periodId)->get();
        $totalGross = $allRecords->sum('gross_pay');
        $totalDeductions = $allRecords->sum('total_deductions');
        $totalNet = $allRecords->sum('net_pay');

        return response()->json([
            'isSuccess' => true,
            'payrolldetails' => $records->items(), // just the current page’s records
            'pagination' => [
                'current_page' => $records->currentPage(),
                'per_page' => $records->perPage(),
                'total' => $records->total(),
                'last_page' => $records->lastPage(),
            ],
            'summary' => [
                'total_gross' => number_format($totalGross, 3, '.', ','),
                'total_deductions' => number_format($totalDeductions, 3, '.', ','),
                'total_net' => number_format($totalNet, 3, '.', ','),
            ],
        ]);
    }



    public function getPayslip($recordId)
    {
        $record = PayrollRecord::with([
            'employee',
            'deductions.benefitType',
            'payrollPeriod',
            'allowances.allowanceType'
        ])->findOrFail($recordId);

        $payslip = [
            'employee_name' => $record->employee->first_name . ' ' . $record->employee->last_name,
            'period' => $record->payrollPeriod->period_name,
            'daily_rate' => $record->daily_rate,
            'days_worked' => $record->days_worked,
            'gross_pay' => $record->gross_pay,
            'allowances' => $record->allowances->map(function ($allowance) {
                return [
                    'allowance_type' => $allowance->allowanceType->type_name ?? null,
                    'allowance_amount' => $allowance->allowance_amount,
                ];
            }),
            'total_allowances' => $record->allowances->sum('allowance_amount'),
            'deductions' => $record->deductions->map(function ($deduction) {
                return [
                    'benefit_type' => $deduction->benefitType->benefit_name ?? null,
                    'deduction_amount' => $deduction->deduction_amount,
                ];
            }),
            'total_deductions' => $record->total_deductions,
            'net_pay' => $record->net_pay,
            'generated_at' => now()->format('F d, Y h:i A'),
        ];

        return response()->json([
            'isSuccess' => true,
            'payslip' => $payslip
        ]);
    }


    public function processPayroll($id)
    {
        try {
            $payroll = PayrollPeriod::findOrFail($id);

            // Change status to processed
            $payroll->status = 'processed';
            $payroll->save();

            return response()->json([
                'isSuccess' => true,
                'message' => 'Payroll period marked as processed successfully.',
                'data' => $payroll,
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating payroll status: ' . $e->getMessage());

            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to update payroll status.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
