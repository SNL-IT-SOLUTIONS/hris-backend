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
            //  Create payroll period
            $period = PayrollPeriod::create([
                'period_name' => $request->period_name,
                'pay_date' => $request->pay_date,
                'cutoff_start_date' => $request->cutoff_start_date,
                'cutoff_end_date' => $request->cutoff_end_date,
            ]);

            // Fetch benefit rates
            $benefits = BenefitType::whereIn('benefit_name', ['SSS', 'Philhealth', 'Pagibig'])
                ->pluck('rate', 'benefit_name');

            foreach ($request->employees as $emp) {
                $employee = Employee::findOrFail($emp['employee_id']);
                $daily = $employee->base_salary; // pull from DB, not the request
                $days = $emp['days_worked'];
                $overtime = $emp['overtime_hours'] ?? 0;
                $absences = $emp['absences'] ?? 0;
                $late_deduction = $emp['late_deductions'] ?? 0;

                //  Compute gross pay
                $gross = ($daily * $days) + ($overtime * ($daily / 8));

                //  Deductions
                $sss = isset($benefits['SSS']) ? $gross * ($benefits['SSS'] / 100) : 0;
                $philhealth = isset($benefits['Philhealth']) ? $gross * ($benefits['Philhealth'] / 100) : 0;
                $pagibig = isset($benefits['Pagibig']) ? $gross * ($benefits['Pagibig'] / 100) : 0;

                $total_deductions = $sss + $philhealth + $pagibig + $late_deduction;
                $net = $gross - $total_deductions;

                //  Create Payroll Record
                $record = PayrollRecord::create([
                    'payroll_period_id' => $period->id,
                    'employee_id' => $employee->id,
                    'daily_rate' => $daily,
                    'days_worked' => $days,
                    'overtime_hours' => $overtime,
                    'absences' => $absences,
                    'late_deductions' => $late_deduction,
                    'gross_pay' => $gross,
                    'total_deductions' => $total_deductions,
                    'net_pay' => $net,
                ]);


                //  Save individual deductions to payroll_deductions table
                $deductions = [
                    ['name' => 'SSS', 'amount' => $sss],
                    ['name' => 'Philhealth', 'amount' => $philhealth],
                    ['name' => 'Pagibig', 'amount' => $pagibig],
                ];

                foreach ($deductions as $ded) {
                    if ($ded['amount'] > 0) {
                        $benefitType = BenefitType::where('benefit_name', $ded['name'])->first();

                        if (!$benefitType) {
                            Log::warning("Benefit type not found: " . $ded['name']);
                            continue;
                        }

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

            DB::commit();

            return response()->json([
                'isSuccess' => true,
                'message' => 'Payroll period and records created successfully.',
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

    /**
     * View payroll details (with deductions)
     */
    public function getPayrollDetails($periodId)
    {
        $records = PayrollRecord::with(['employee', 'deductions.benefitType'])
            ->where('payroll_period_id', $periodId)
            ->get();

        return response()->json([
            'isSuccess' => true,
            'payrolldetails' => $records,
        ]);
    }

    //Helper
    private function storePayrollDeductions($payrollRecordId, $employee)
    {
        $benefits = BenefitType::all();

        foreach ($benefits as $benefit) {
            $rate = $benefit->deduction_rate ?? 0;
            $deductionAmount = $employee->daily_rate * $rate;

            PayrollDeduction::create([
                'payroll_record_id' => $payrollRecordId,
                'benefit_type_id'   => $benefit->id,
                'deduction_name'    => $benefit->benefit_name,
                'deduction_rate'    => $rate,
                'deduction_amount'  => $deductionAmount,
            ]);
        }
    }
}
