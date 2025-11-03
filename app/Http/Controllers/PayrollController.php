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

    public function getPayrollSummary()
    {
        $summary = [
            'total_periods' => DB::table('payroll_periods')->count(),
            'processed' => DB::table('payroll_periods')->where('status', 'processed')->count(),
            'drafts' => DB::table('payroll_periods')->where('status', 'draft')->count(),
            'active_employees' => DB::table('employees')->where('employment_status', 'active')->count(),
        ];

        return response()->json([
            'isSuccess' => true,
            'data' => $summary,
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

        // Compute total summary values
        $totalGross = $records->sum('gross_pay');
        $totalDeductions = $records->sum('total_deductions');
        $totalNet = $records->sum('net_pay');

        return response()->json([
            'isSuccess' => true,
            'payrolldetails' => $records,
            'summary' => [
                'total_gross' => number_format($totalGross, 3, '.', ','),
                'total_deductions' => number_format($totalDeductions, 3, '.', ','),
                'total_net' => number_format($totalNet, 3, '.', ','),
            ],
        ]);
    }


    public function getPayslip($recordId)
    {
        $record = PayrollRecord::with(['employee', 'deductions.benefitType', 'payrollPeriod'])
            ->findOrFail($recordId);

        $payslip = [
            'employee_name' => $record->employee->first_name . ' ' . $record->employee->last_name,
            'period' => $record->payrollPeriod->period_name,
            'daily_rate' => $record->daily_rate,
            'days_worked' => $record->days_worked,
            'gross_pay' => $record->gross_pay,
            'deductions' => $record->deductions->map(function ($deduction) {
                return [
                    'benefit_type' => $deduction->benefitType->name ?? null,
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



    //Helper

}
