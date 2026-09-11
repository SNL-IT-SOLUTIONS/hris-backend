<?php

namespace App\Http\Controllers;

use App\Models\{
    PayrollPeriod,
    PayrollRecord,
    PayrollDeduction,
    PayrollAllowance,
    Employee,
    Loan,
    ThirteenthMonth,
    ThirteenthMonthPeriod,
    Attendance,
    Holiday
};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Log};
use Carbon\Carbon;
use Carbon\CarbonPeriod;

use function PHPUnit\Framework\isNull;

class PayrollController extends Controller
{
    /**
     * Create a new payroll period and generate records for selected employees
     */


    public function createPayrollPeriod(Request $request)
    {
        $validated = $request->validate([
            'period_name' => 'required|string|max:255',
            'pay_date' => 'required|date',
            'cutoff_start_date' => 'required|date',
            'cutoff_end_date' => 'required|date|after_or_equal:cutoff_start_date',

            'employees' => 'required|array|min:1',

            'employees.*.employee_id' => 'required|integer|exists:employees,id',
            'employees.*.remarks' => 'nullable|string',
            'employees.*.days_worked' => 'nullable|numeric|min:0',
            'employees.*.absences' => 'nullable|numeric|min:0',
            'employees.*.overtime_hours' => 'nullable|numeric|min:0',
        ]);

        DB::beginTransaction();

        try {

            /*
        |--------------------------------------------------------------------------
        | CREATE PAYROLL PERIOD
        |--------------------------------------------------------------------------
        */

            $payrollPeriod = PayrollPeriod::create([
                'period_name' => $request->period_name,
                'pay_date' => $request->pay_date,
                'cutoff_start_date' => $request->cutoff_start_date,
                'cutoff_end_date' => $request->cutoff_end_date,
                'status' => 'processed',
            ]);


            /*
        |--------------------------------------------------------------------------
        | GET HOLIDAYS WITHIN CUTOFF
        |--------------------------------------------------------------------------
        */

            $holidays = Holiday::with('holidayType')
                ->whereBetween('holiday_date', [
                    $request->cutoff_start_date,
                    $request->cutoff_end_date
                ])
                ->where('is_archived', 0)
                ->get()
                ->keyBy(function ($holiday) {
                    return Carbon::parse(
                        $holiday->holiday_date
                    )->toDateString();
                });


            /*
        |--------------------------------------------------------------------------
        | CALCULATE EXPECTED WORKING DAYS
        |--------------------------------------------------------------------------
        |
        | Weekdays excluding holidays.
        |
        */

            $cutoffDays = 0;

            $cutoffPeriod = CarbonPeriod::create(
                Carbon::parse($request->cutoff_start_date)->startOfDay(),
                Carbon::parse($request->cutoff_end_date)->startOfDay()
            );

            foreach ($cutoffPeriod as $date) {

                /*
            | Ignore Saturday and Sunday
            */

                if ($date->isWeekend()) {
                    continue;
                }

                $dateString = $date->toDateString();

                /*
            | Ignore PH and US holidays
            */

                if ($holidays->has($dateString)) {
                    continue;
                }

                $cutoffDays++;
            }


            /*
        |--------------------------------------------------------------------------
        | LOOP THROUGH SELECTED EMPLOYEES
        |--------------------------------------------------------------------------
        */

            foreach ($request->employees as $employeeInput) {

                /*
            |--------------------------------------------------------------------------
            | GET EMPLOYEE
            |--------------------------------------------------------------------------
            */

                $employee = Employee::where('id', $employeeInput['employee_id'])
                    ->where('is_active', 1)
                    ->where('is_archived', 0)
                    ->first();

                if (!$employee) {
                    throw new \Exception(
                        'Employee with ID ' .
                            $employeeInput['employee_id'] .
                            ' was not found or is inactive.'
                    );
                }


                /*
            |--------------------------------------------------------------------------
            | EMPLOYEE INPUT VALUES
            |--------------------------------------------------------------------------
            */

                $manualDays = $employeeInput['days_worked'] ?? null;

                $manualAbs = $employeeInput['absences'] ?? null;

                $overtime = $employeeInput['overtime_hours'] ?? 0;


                /*
            |--------------------------------------------------------------------------
            | DAILY / HOURLY RATE
            |--------------------------------------------------------------------------
            */

                $daily = $employee->base_salary;

                $hourlyRate = $daily / 8;


                /*
            |--------------------------------------------------------------------------
            | GET ATTENDANCE RECORDS
            |--------------------------------------------------------------------------
            */

                $attendanceRecords = Attendance::where(
                    'employee_id',
                    $employee->id
                )
                    ->whereIn('status', [
                        'Present',
                        'Late'
                    ])
                    ->where(function ($query) use ($request) {

                        $query->whereBetween(
                            DB::raw('DATE(clock_in)'),
                            [
                                $request->cutoff_start_date,
                                $request->cutoff_end_date
                            ]
                        )
                            ->orWhereBetween(
                                DB::raw('DATE(clock_out)'),
                                [
                                    $request->cutoff_start_date,
                                    $request->cutoff_end_date
                                ]
                            );
                    })
                    ->get()
                    ->unique('id');


                /*
            |--------------------------------------------------------------------------
            | GET ACTUAL ATTENDANCE DATES
            |--------------------------------------------------------------------------
            |
            | Used to prevent attendance + leave from being counted twice.
            |
            */

                $attendanceDates = collect();

                foreach ($attendanceRecords as $attendance) {

                    $attendanceDate = Carbon::parse(
                        $attendance->clock_in
                            ?? $attendance->clock_out
                    )->toDateString();

                    $attendanceDates->push($attendanceDate);
                }

                $attendanceDates = $attendanceDates
                    ->unique()
                    ->values();


                /*
            |--------------------------------------------------------------------------
            | GET APPROVED LEAVES
            |--------------------------------------------------------------------------
            |
            | ALL APPROVED LEAVES ARE PAID.
            |
            | We intentionally do not check leaves.is_paid because your
            | business rule is that every approved leave is guaranteed paid.
            |
            */

                $approvedLeaves = DB::table('leaves')
                    ->where('employee_id', $employee->id)
                    ->where('status', 'Approved')
                    ->where('is_archived', 0)
                    ->where(function ($query) use ($request) {

                        /*
                    | Leave starts inside cutoff
                    */

                        $query->whereBetween('start_date', [
                            $request->cutoff_start_date,
                            $request->cutoff_end_date
                        ])

                            /*
                    | Leave ends inside cutoff
                    */

                            ->orWhereBetween('end_date', [
                                $request->cutoff_start_date,
                                $request->cutoff_end_date
                            ])

                            /*
                    | Leave completely covers cutoff
                    */

                            ->orWhere(function ($q) use ($request) {

                                $q->where(
                                    'start_date',
                                    '<=',
                                    $request->cutoff_start_date
                                )
                                    ->where(
                                        'end_date',
                                        '>=',
                                        $request->cutoff_end_date
                                    );
                            });
                    })
                    ->get();


                /*
            |--------------------------------------------------------------------------
            | BUILD PAID LEAVE DATES
            |--------------------------------------------------------------------------
            */

                $paidLeaveDates = collect();

                foreach ($approvedLeaves as $leave) {

                    $leaveStart = Carbon::parse(
                        $leave->start_date
                    )->startOfDay();

                    $leaveEnd = Carbon::parse(
                        $leave->end_date
                    )->startOfDay();

                    $cutoffStart = Carbon::parse(
                        $request->cutoff_start_date
                    )->startOfDay();

                    $cutoffEnd = Carbon::parse(
                        $request->cutoff_end_date
                    )->startOfDay();


                    /*
                |--------------------------------------------------------------------------
                | LIMIT LEAVE TO PAYROLL CUTOFF
                |--------------------------------------------------------------------------
                */

                    if ($leaveStart->lt($cutoffStart)) {
                        $leaveStart = $cutoffStart->copy();
                    }

                    if ($leaveEnd->gt($cutoffEnd)) {
                        $leaveEnd = $cutoffEnd->copy();
                    }

                    if ($leaveStart->gt($leaveEnd)) {
                        continue;
                    }


                    /*
                |--------------------------------------------------------------------------
                | LOOP EACH LEAVE DATE
                |--------------------------------------------------------------------------
                */

                    $leavePeriod = CarbonPeriod::create(
                        $leaveStart,
                        $leaveEnd
                    );

                    foreach ($leavePeriod as $leaveDate) {

                        /*
                    | Leave on weekend is not a working day.
                    */

                        if ($leaveDate->isWeekend()) {
                            continue;
                        }

                        $dateString = $leaveDate->toDateString();


                        /*
                    |--------------------------------------------------------------------------
                    | HOLIDAYS ARE HANDLED SEPARATELY
                    |--------------------------------------------------------------------------
                    |
                    | Don't pay a holiday as both holiday + leave.
                    |
                    */

                        if ($holidays->has($dateString)) {
                            continue;
                        }


                        /*
                    |--------------------------------------------------------------------------
                    | DON'T DOUBLE COUNT ATTENDANCE + LEAVE
                    |--------------------------------------------------------------------------
                    |
                    | If employee actually worked on a leave date,
                    | attendance already pays that day.
                    |
                    */

                        if ($attendanceDates->contains($dateString)) {
                            continue;
                        }


                        /*
                    |--------------------------------------------------------------------------
                    | APPROVED LEAVE = PAID DAY
                    |--------------------------------------------------------------------------
                    */

                        $paidLeaveDates->push($dateString);
                    }
                }


                /*
            |--------------------------------------------------------------------------
            | REMOVE DUPLICATE LEAVE DATES
            |--------------------------------------------------------------------------
            */

                $paidLeaveDates = $paidLeaveDates
                    ->unique()
                    ->values();


                /*
            |--------------------------------------------------------------------------
            | TOTAL PAID LEAVE DAYS
            |--------------------------------------------------------------------------
            */

                $paidLeaveDays = $paidLeaveDates->count();


                /*
            |--------------------------------------------------------------------------
            | INITIALIZE PAY
            |--------------------------------------------------------------------------
            */

                $normalPay = 0;

                $holidayPay = 0;

                $actualNormalWorkedDays = 0;

                $phHolidayWorkedDays = 0;


                /*
            |--------------------------------------------------------------------------
            | PROCESS ATTENDANCE PAY
            |--------------------------------------------------------------------------
            */

                foreach ($attendanceRecords as $attendance) {

                    $attendanceDate = Carbon::parse(
                        $attendance->clock_in
                            ?? $attendance->clock_out
                    );

                    $dateString = $attendanceDate->toDateString();


                    /*
                |--------------------------------------------------------------------------
                | US HOLIDAY
                |--------------------------------------------------------------------------
                |
                | Employee can have attendance on US holiday,
                | but receives ₱0 holiday pay.
                |
                */

                    if ($holidays->has($dateString)) {

                        $holiday = $holidays->get($dateString);

                        if (
                            $holiday->holidayType &&
                            strtoupper(
                                $holiday->holidayType->country ?? ''
                            ) === 'US'
                        ) {
                            continue;
                        }


                        /*
                    |--------------------------------------------------------------------------
                    | PH HOLIDAY
                    |--------------------------------------------------------------------------
                    */

                        $holidayRate =
                            $holiday->holidayType->rate ?? 1;

                        $holidayPay +=
                            $daily * $holidayRate;

                        $phHolidayWorkedDays++;

                        continue;
                    }


                    /*
                |--------------------------------------------------------------------------
                | NORMAL WORKING DAY
                |--------------------------------------------------------------------------
                */

                    $normalPay += $daily;

                    $actualNormalWorkedDays++;
                }


                /*
            |--------------------------------------------------------------------------
            | PAID LEAVE PAY
            |--------------------------------------------------------------------------
            |
            | Every approved leave day receives the employee's daily rate.
            |
            */

                $paidLeavePay =
                    $daily * $paidLeaveDays;


                /*
            |--------------------------------------------------------------------------
            | ADD PAID LEAVE TO NORMAL PAY
            |--------------------------------------------------------------------------
            */

                $normalPay += $paidLeavePay;


                /*
            |--------------------------------------------------------------------------
            | TOTAL PAID / PRESENT DAYS
            |--------------------------------------------------------------------------
            |
            | Paid leave behaves as present for payroll.
            |
            */

                $paidDays =
                    $actualNormalWorkedDays
                    + $paidLeaveDays
                    + $phHolidayWorkedDays;


                /*
            |--------------------------------------------------------------------------
            | ABSENCES
            |--------------------------------------------------------------------------
            */

                if ($manualAbs !== null) {

                    /*
                |--------------------------------------------------------------------------
                | MANUAL ABSENCE OVERRIDE
                |--------------------------------------------------------------------------
                */

                    $absences = $manualAbs;
                } else {

                    /*
                |--------------------------------------------------------------------------
                | AUTOMATIC ABSENCE CALCULATION
                |--------------------------------------------------------------------------
                |
                | Paid leave is NOT an absence.
                |
                */

                    $absences = max(
                        $cutoffDays
                            - $actualNormalWorkedDays
                            - $paidLeaveDays
                            - $phHolidayWorkedDays,
                        0
                    );
                }


                /*
            |--------------------------------------------------------------------------
            | NIGHT DIFFERENTIAL
            |--------------------------------------------------------------------------
            |
            | Paid leave does NOT receive night differential.
            | Only actual worked days receive it.
            |
            */

                $nightHours =
                    $employee->night_hours ?? 0;

                $nightRate =
                    $employee->night_rate ?? 10;

                $nightDiffPerDay =
                    $hourlyRate
                    * ($nightRate / 100)
                    * $nightHours;

                $totalNightDiff =
                    $nightDiffPerDay
                    * $actualNormalWorkedDays;


                /*
            |--------------------------------------------------------------------------
            | OVERTIME
            |--------------------------------------------------------------------------
            |
            | Overtime = hourly rate × 1.25
            |
            | Paid leave does not generate overtime.
            |
            */

                $overtimePay =
                    $overtime
                    * ($hourlyRate * 1.25);


                /*
            |--------------------------------------------------------------------------
            | GROSS BASE
            |--------------------------------------------------------------------------
            */

                $grossBase =
                    $normalPay
                    + $holidayPay
                    + $overtimePay;


                /*
            |--------------------------------------------------------------------------
            | GET EMPLOYEE ALLOWANCES
            |--------------------------------------------------------------------------
            */

                $allowances = DB::table('employee_allowance')
                    ->join(
                        'allowance_types',
                        'employee_allowance.allowance_type_id',
                        '=',
                        'allowance_types.id'
                    )
                    ->where(
                        'employee_allowance.employee_id',
                        $employee->id
                    )
                    ->where(
                        'employee_allowance.is_active',
                        1
                    )
                    ->where(
                        'employee_allowance.is_archived',
                        0
                    )
                    ->where(
                        'allowance_types.is_archived',
                        0
                    )
                    ->select(
                        'allowance_types.id as allowance_type_id',
                        'allowance_types.allowance_name',
                        'allowance_types.amount',
                        'allowance_types.is_perfect_attendance'
                    )
                    ->get();


                /*
            |--------------------------------------------------------------------------
            | CHECK LATE ATTENDANCE
            |--------------------------------------------------------------------------
            */

                $hasLate = Attendance::where(
                    'employee_id',
                    $employee->id
                )
                    ->where('status', 'Late')
                    ->where(function ($query) use ($request) {

                        $query->whereBetween(
                            DB::raw('DATE(clock_in)'),
                            [
                                $request->cutoff_start_date,
                                $request->cutoff_end_date
                            ]
                        )
                            ->orWhereBetween(
                                DB::raw('DATE(clock_out)'),
                                [
                                    $request->cutoff_start_date,
                                    $request->cutoff_end_date
                                ]
                            );
                    })
                    ->exists();


                /*
            |--------------------------------------------------------------------------
            | CALCULATE ALLOWANCES
            |--------------------------------------------------------------------------
            */

                $totalAllowances = 0;

                $allowanceRecords = [];


                foreach ($allowances as $allowance) {

                    /*
                |--------------------------------------------------------------------------
                | PERFECT ATTENDANCE
                |--------------------------------------------------------------------------
                |
                | Paid leave does NOT count as absence.
                |
                | Therefore approved paid leave does not automatically
                | disqualify the employee based on absence count.
                |
                */

                    if ($allowance->is_perfect_attendance) {

                        if (
                            $absences == 0 &&
                            !$hasLate
                        ) {

                            $allowanceAmount =
                                $allowance->amount;
                        } else {

                            $allowanceAmount = 0;
                        }
                    } else {

                        /*
                    |--------------------------------------------------------------------------
                    | NORMAL ALLOWANCE
                    |--------------------------------------------------------------------------
                    |
                    | Semi-monthly = monthly amount / 2
                    |
                    */

                        $allowanceAmount =
                            $allowance->amount / 2;
                    }


                    if ($allowanceAmount > 0) {

                        $totalAllowances +=
                            $allowanceAmount;

                        $allowanceRecords[] = [
                            'allowance_type_id' =>
                            $allowance->allowance_type_id,

                            'amount' =>
                            $allowanceAmount,
                        ];
                    }
                }


                /*
            |--------------------------------------------------------------------------
            | GROSS INCLUDING ALLOWANCES
            |--------------------------------------------------------------------------
            */

                $grossWithAllowances =
                    $grossBase
                    + $totalAllowances
                    + $totalNightDiff;


                /*
            |--------------------------------------------------------------------------
            | GET EMPLOYEE BENEFITS
            |--------------------------------------------------------------------------
            */

                $benefits = DB::table('employee_benefit')
                    ->join(
                        'benefit_types',
                        'employee_benefit.benefit_type_id',
                        '=',
                        'benefit_types.id'
                    )
                    ->where(
                        'employee_benefit.employee_id',
                        $employee->id
                    )
                    ->where(
                        'employee_benefit.is_active',
                        1
                    )
                    ->where(
                        'employee_benefit.is_archived',
                        0
                    )
                    ->where(
                        'benefit_types.is_archived',
                        0
                    )
                    ->select(
                        'benefit_types.id as benefit_type_id',
                        'benefit_types.benefit_name',
                        'benefit_types.amount'
                    )
                    ->get();


                /*
            |--------------------------------------------------------------------------
            | BENEFIT DEDUCTIONS
            |--------------------------------------------------------------------------
            |
            | Semi-monthly = monthly amount / 2
            |
            */

                $totalBenefitDeductions = 0;

                $benefitRecords = [];

                foreach ($benefits as $benefit) {

                    $deductionAmount =
                        $benefit->amount / 2;

                    $totalBenefitDeductions +=
                        $deductionAmount;

                    $benefitRecords[] = [
                        'benefit_type_id' =>
                        $benefit->benefit_type_id,

                        'amount' =>
                        $deductionAmount,
                    ];
                }


                /*
            |--------------------------------------------------------------------------
            | LATE DEDUCTIONS
            |--------------------------------------------------------------------------
            */

                $totalLateDeductions = Attendance::where(
                    'employee_id',
                    $employee->id
                )
                    ->whereIn('status', [
                        'Present',
                        'Late'
                    ])
                    ->where(function ($query) use ($request) {

                        $query->whereBetween(
                            DB::raw('DATE(clock_in)'),
                            [
                                $request->cutoff_start_date,
                                $request->cutoff_end_date
                            ]
                        )
                            ->orWhereBetween(
                                DB::raw('DATE(clock_out)'),
                                [
                                    $request->cutoff_start_date,
                                    $request->cutoff_end_date
                                ]
                            );
                    })
                    ->sum('late_deduction');


                /*
            |--------------------------------------------------------------------------
            | GET ACTIVE LOANS
            |--------------------------------------------------------------------------
            */

                $loans = Loan::where(
                    'employee_id',
                    $employee->id
                )
                    ->where('is_archived', 0)
                    ->whereIn('status', [
                        'active',
                        'Active'
                    ])
                    ->get();


                /*
            |--------------------------------------------------------------------------
            | LOAN DEDUCTIONS
            |--------------------------------------------------------------------------
            |
            | Semi-monthly = monthly amortization / 2
            |--------------------------------------------------------------------------
            */

                $totalLoanDeductions = 0;

                $loanRecords = [];

                foreach ($loans as $loan) {

                    $loanDeduction =
                        $loan->monthly_amortization / 2;

                    /*
                |--------------------------------------------------------------------------
                | Don't deduct more than remaining balance
                |--------------------------------------------------------------------------
                */

                    if (
                        isset($loan->remaining_balance) &&
                        $loan->remaining_balance !== null
                    ) {

                        $loanDeduction = min(
                            $loanDeduction,
                            $loan->remaining_balance
                        );
                    }

                    $totalLoanDeductions +=
                        $loanDeduction;

                    $loanRecords[] = [
                        'loan' => $loan,
                        'amount' => $loanDeduction,
                    ];
                }


                /*
            |--------------------------------------------------------------------------
            | TOTAL DEDUCTIONS
            |--------------------------------------------------------------------------
            */

                $totalDeductions =
                    $totalBenefitDeductions
                    + $totalLoanDeductions
                    + $totalLateDeductions;


                /*
            |--------------------------------------------------------------------------
            | NET PAY
            |--------------------------------------------------------------------------
            */

                $netPay =
                    $grossWithAllowances
                    - $totalDeductions;


                /*
            |--------------------------------------------------------------------------
            | CREATE PAYROLL RECORD
            |--------------------------------------------------------------------------
            */

                $payrollRecord = PayrollRecord::create([
                    'payroll_period_id' =>
                    $payrollPeriod->id,

                    'employee_id' =>
                    $employee->id,

                    'daily_rate' =>
                    $daily,

                    /*
                | Paid leave is counted as a paid/present day.
                */

                    'days_worked' =>
                    $paidDays,

                    'overtime_hours' =>
                    $overtime,

                    /*
                | Paid leave is NOT an absence.
                */

                    'absences' =>
                    $absences,

                    'holiday_pay' =>
                    $holidayPay,

                    'night_diff_pay' =>
                    $totalNightDiff,

                    'gross_base' =>
                    $grossBase,

                    'gross_pay' =>
                    $grossWithAllowances,

                    'total_allowances' =>
                    $totalAllowances,

                    'total_loan_deductions' =>
                    $totalLoanDeductions,

                    'total_late_deductions' =>
                    $totalLateDeductions,

                    'total_deductions' =>
                    $totalDeductions,

                    'net_pay' =>
                    $netPay,

                    'remarks' =>
                    $employeeInput['remarks'] ?? null,
                ]);


                /*
            |--------------------------------------------------------------------------
            | SAVE BENEFIT DEDUCTIONS
            |--------------------------------------------------------------------------
            */

                foreach ($benefitRecords as $benefitRecord) {

                    PayrollDeduction::create([
                        'payroll_record_id' =>
                        $payrollRecord->id,

                        'benefit_type_id' =>
                        $benefitRecord['benefit_type_id'],

                        'amount' =>
                        $benefitRecord['amount'],
                    ]);
                }


                /*
            |--------------------------------------------------------------------------
            | SAVE LOAN DEDUCTIONS + UPDATE LOAN BALANCE
            |--------------------------------------------------------------------------
            */

                foreach ($loanRecords as $loanRecord) {

                    $loan = $loanRecord['loan'];

                    $deductionAmount =
                        $loanRecord['amount'];


                    /*
                |--------------------------------------------------------------------------
                | SAVE LOAN DEDUCTION
                |--------------------------------------------------------------------------
                */

                    PayrollDeduction::create([
                        'payroll_record_id' =>
                        $payrollRecord->id,

                        'loan_id' =>
                        $loan->id,

                        'amount' =>
                        $deductionAmount,
                    ]);


                    /*
                |--------------------------------------------------------------------------
                | UPDATE LOAN BALANCE
                |--------------------------------------------------------------------------
                */

                    if (
                        isset($loan->remaining_balance) &&
                        $loan->remaining_balance !== null
                    ) {

                        $newBalance =
                            $loan->remaining_balance
                            - $deductionAmount;

                        if ($newBalance <= 0) {

                            $loan->remaining_balance = 0;

                            $loan->status = 'paid';
                        } else {

                            $loan->remaining_balance =
                                $newBalance;
                        }

                        $loan->save();
                    }
                }


                /*
            |--------------------------------------------------------------------------
            | SAVE ALLOWANCES
            |--------------------------------------------------------------------------
            */

                foreach ($allowanceRecords as $allowanceRecord) {

                    PayrollAllowance::create([
                        'payroll_record_id' =>
                        $payrollRecord->id,

                        'allowance_type_id' =>
                        $allowanceRecord['allowance_type_id'],

                        'amount' =>
                        $allowanceRecord['amount'],
                    ]);
                }
            }


            /*
        |--------------------------------------------------------------------------
        | COMMIT
        |--------------------------------------------------------------------------
        */

            DB::commit();


            /*
        |--------------------------------------------------------------------------
        | RESPONSE
        |--------------------------------------------------------------------------
        */

            return response()->json([
                'isSuccess' => true,
                'message' => 'Payroll period created and processed successfully.',
                'data' => $payrollPeriod,
            ], 201);
        } catch (\Exception $e) {

            /*
        |--------------------------------------------------------------------------
        | ROLLBACK
        |--------------------------------------------------------------------------
        */

            DB::rollBack();

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


    public function getEmployees(Request $request)
    {
        try {

            $request->validate([
                'cutoff_start_date' => 'nullable|date',
                'cutoff_end_date'   => 'nullable|date|after_or_equal:cutoff_start_date',
            ]);

            /*
        |--------------------------------------------------------------------------
        | CUTOFF DATES
        |--------------------------------------------------------------------------
        */

            $start = $request->cutoff_start_date
                ? Carbon::parse($request->cutoff_start_date)->startOfDay()
                : null;

            $end = $request->cutoff_end_date
                ? Carbon::parse($request->cutoff_end_date)->endOfDay()
                : null;


            /*
        |--------------------------------------------------------------------------
        | GET HOLIDAYS
        |--------------------------------------------------------------------------
        */

            $holidays = collect();

            if ($start && $end) {

                $holidays = Holiday::with('holidayType')
                    ->whereBetween('holiday_date', [
                        $start->toDateString(),
                        $end->toDateString()
                    ])
                    ->where('is_archived', 0)
                    ->get()
                    ->keyBy(function ($holiday) {
                        return Carbon::parse(
                            $holiday->holiday_date
                        )->toDateString();
                    });
            }


            /*
        |--------------------------------------------------------------------------
        | CALCULATE TOTAL WORKING DAYS
        |--------------------------------------------------------------------------
        |
        | Weekdays only.
        | All holidays are excluded.
        |
        */

            $totalWorkingDays = 0;

            if ($start && $end) {

                $period = CarbonPeriod::create(
                    $start->copy()->startOfDay(),
                    $end->copy()->startOfDay()
                );

                foreach ($period as $date) {

                    // Saturday / Sunday
                    if ($date->isWeekend()) {
                        continue;
                    }

                    $dateString = $date->toDateString();

                    // Holiday
                    if ($holidays->has($dateString)) {
                        continue;
                    }

                    $totalWorkingDays++;
                }
            }


            /*
        |--------------------------------------------------------------------------
        | GET ACTIVE EMPLOYEES
        |--------------------------------------------------------------------------
        */

            $employees = Employee::with([
                'department:id,department_name',
                'position:id,position_name'
            ])
                ->where('is_active', 1)
                ->where('is_archived', 0)
                ->get();


            /*
        |--------------------------------------------------------------------------
        | GET ATTENDANCE
        |--------------------------------------------------------------------------
        */

            $attendanceData = collect();

            if ($start && $end) {

                $attendanceData = DB::table('attendances')
                    ->whereIn('status', [
                        'Present',
                        'Late'
                    ])
                    ->where(function ($query) use ($start, $end) {

                        $query->whereBetween(
                            'clock_in',
                            [$start, $end]
                        )
                            ->orWhereBetween(
                                'clock_out',
                                [$start, $end]
                            );
                    })
                    ->select(
                        'employee_id',
                        DB::raw(
                            'DATE(COALESCE(clock_in, clock_out)) as work_date'
                        )
                    )
                    ->get()
                    ->groupBy('employee_id');
            }


            /*
        |--------------------------------------------------------------------------
        | GET APPROVED LEAVES
        |--------------------------------------------------------------------------
        |
        | ALL APPROVED LEAVES ARE PAID.
        |
        | We do not check is_paid because your business rule says
        | every approved leave is guaranteed paid.
        |
        */

            $leaveData = collect();

            if ($start && $end) {

                $leaveData = DB::table('leaves')
                    ->where('status', 'Approved')
                    ->where('is_archived', 0)
                    ->where(function ($query) use ($start, $end) {

                        /*
                    | Leave starts inside cutoff
                    */

                        $query->whereBetween('start_date', [
                            $start->toDateString(),
                            $end->toDateString()
                        ])

                            /*
                    | Leave ends inside cutoff
                    */

                            ->orWhereBetween('end_date', [
                                $start->toDateString(),
                                $end->toDateString()
                            ])

                            /*
                    | Leave completely covers cutoff
                    */

                            ->orWhere(function ($q) use ($start, $end) {

                                $q->where(
                                    'start_date',
                                    '<=',
                                    $start->toDateString()
                                )
                                    ->where(
                                        'end_date',
                                        '>=',
                                        $end->toDateString()
                                    );
                            });
                    })
                    ->select(
                        'employee_id',
                        'start_date',
                        'end_date',
                        'total_days'
                    )
                    ->get()
                    ->groupBy('employee_id');
            }


            /*
        |--------------------------------------------------------------------------
        | BUILD EMPLOYEE DATA
        |--------------------------------------------------------------------------
        */

            $employeeData = $employees->map(
                function ($employee) use (
                    $attendanceData,
                    $leaveData,
                    $holidays,
                    $totalWorkingDays,
                    $start,
                    $end
                ) {

                    /*
                |--------------------------------------------------------------------------
                | DATE COLLECTIONS
                |--------------------------------------------------------------------------
                */

                    $actualWorkedDates = collect();

                    $paidLeaveDates = collect();

                    $usHolidayPresentDays = 0;

                    $phHolidayWorkedDays = 0;


                    /*
                |--------------------------------------------------------------------------
                | PROCESS ATTENDANCE
                |--------------------------------------------------------------------------
                */

                    $employeeAttendance = $attendanceData->get(
                        $employee->id,
                        collect()
                    );

                    foreach ($employeeAttendance as $attendance) {

                        $workDate = Carbon::parse(
                            $attendance->work_date
                        );

                        /*
                    | Make sure attendance is inside cutoff
                    */

                        if (
                            $start &&
                            $workDate->lt(
                                $start->copy()->startOfDay()
                            )
                        ) {
                            continue;
                        }

                        if (
                            $end &&
                            $workDate->gt(
                                $end->copy()->endOfDay()
                            )
                        ) {
                            continue;
                        }


                        /*
                    | Ignore weekends
                    */

                        if ($workDate->isWeekend()) {
                            continue;
                        }


                        $dateString = $workDate->toDateString();


                        /*
                    |--------------------------------------------------------------------------
                    | HOLIDAY ATTENDANCE
                    |--------------------------------------------------------------------------
                    */

                        if ($holidays->has($dateString)) {

                            $holiday = $holidays->get($dateString);


                            /*
                        | US HOLIDAY
                        |
                        | Employee can be present, but payroll pays ₱0.
                        */

                            if (
                                $holiday->holidayType &&
                                strtoupper(
                                    $holiday->holidayType->country ?? ''
                                ) === 'US'
                            ) {

                                $usHolidayPresentDays++;

                                continue;
                            }


                            /*
                        | PH HOLIDAY
                        |
                        | Employee worked on PH holiday.
                        */

                            $phHolidayWorkedDays++;

                            continue;
                        }


                        /*
                    |--------------------------------------------------------------------------
                    | NORMAL WORK DAY
                    |--------------------------------------------------------------------------
                    */

                        $actualWorkedDates->push(
                            $dateString
                        );
                    }


                    /*
                |--------------------------------------------------------------------------
                | PROCESS APPROVED PAID LEAVES
                |--------------------------------------------------------------------------
                */

                    $employeeLeaves = $leaveData->get(
                        $employee->id,
                        collect()
                    );

                    foreach ($employeeLeaves as $leave) {

                        $leaveStart = Carbon::parse(
                            $leave->start_date
                        )->startOfDay();

                        $leaveEnd = Carbon::parse(
                            $leave->end_date
                        )->startOfDay();


                        /*
                    |--------------------------------------------------------------------------
                    | LIMIT LEAVE TO CUTOFF
                    |--------------------------------------------------------------------------
                    */

                        if (
                            $start &&
                            $leaveStart->lt(
                                $start->copy()->startOfDay()
                            )
                        ) {

                            $leaveStart = $start
                                ->copy()
                                ->startOfDay();
                        }

                        if (
                            $end &&
                            $leaveEnd->gt(
                                $end->copy()->startOfDay()
                            )
                        ) {

                            $leaveEnd = $end
                                ->copy()
                                ->startOfDay();
                        }


                        if ($leaveStart->gt($leaveEnd)) {
                            continue;
                        }


                        /*
                    |--------------------------------------------------------------------------
                    | LOOP EACH LEAVE DATE
                    |--------------------------------------------------------------------------
                    */

                        $leavePeriod = CarbonPeriod::create(
                            $leaveStart,
                            $leaveEnd
                        );

                        foreach ($leavePeriod as $leaveDate) {

                            /*
                        | Leave on weekend = not a working day
                        */

                            if ($leaveDate->isWeekend()) {
                                continue;
                            }


                            $dateString = $leaveDate->toDateString();


                            /*
                        |--------------------------------------------------------------------------
                        | HOLIDAYS
                        |--------------------------------------------------------------------------
                        |
                        | Holiday handling stays separate.
                        | Do not count holiday as another leave day.
                        |
                        */

                            if ($holidays->has($dateString)) {
                                continue;
                            }


                            /*
                        |--------------------------------------------------------------------------
                        | DON'T DOUBLE COUNT ATTENDANCE + LEAVE
                        |--------------------------------------------------------------------------
                        */

                            if (
                                $actualWorkedDates->contains(
                                    $dateString
                                )
                            ) {
                                continue;
                            }


                            /*
                        |--------------------------------------------------------------------------
                        | APPROVED PAID LEAVE = PAID/PRESENT
                        |--------------------------------------------------------------------------
                        */

                            $paidLeaveDates->push(
                                $dateString
                            );
                        }
                    }


                    /*
                |--------------------------------------------------------------------------
                | REMOVE DUPLICATES
                |--------------------------------------------------------------------------
                */

                    $actualWorkedDates = $actualWorkedDates
                        ->unique()
                        ->values();

                    $paidLeaveDates = $paidLeaveDates
                        ->unique()
                        ->values();


                    /*
                |--------------------------------------------------------------------------
                | DAY COUNTS
                |--------------------------------------------------------------------------
                */

                    $actualWorkedDays =
                        $actualWorkedDates->count();

                    $paidLeaveDays =
                        $paidLeaveDates->count();


                    /*
                |--------------------------------------------------------------------------
                | TOTAL PAID/PRESENT DAYS
                |--------------------------------------------------------------------------
                |
                | Paid leave is treated as present.
                |
                */

                    $paidPresentDays =
                        $actualWorkedDays
                        + $paidLeaveDays
                        + $phHolidayWorkedDays;


                    /*
                |--------------------------------------------------------------------------
                | ABSENCES
                |--------------------------------------------------------------------------
                |
                | Paid leave is NOT an absence.
                |--------------------------------------------------------------------------
                */

                    $absences = max(
                        $totalWorkingDays
                            - $actualWorkedDays
                            - $paidLeaveDays
                            - $phHolidayWorkedDays,
                        0
                    );


                    /*
                |--------------------------------------------------------------------------
                | DISPLAY DAYS WORKED
                |--------------------------------------------------------------------------
                |
                | This represents total paid/present days.
                |--------------------------------------------------------------------------
                */

                    $displayDaysWorked =
                        $actualWorkedDays
                        + $paidLeaveDays
                        + $phHolidayWorkedDays;


                    return [
                        'id' => $employee->id,

                        'employee_id' => $employee->employee_id,

                        'first_name' => $employee->first_name,

                        'last_name' => $employee->last_name,

                        'department' => $employee->department,

                        'position' => $employee->position,

                        /*
                    | Total paid/present days
                    */

                        'days_worked' => $displayDaysWorked,

                        /*
                    | Actual physical attendance
                    */

                        'actual_worked_days' => $actualWorkedDays,

                        /*
                    | Approved paid leave
                    */

                        'paid_leave_days' => $paidLeaveDays,

                        /*
                    | PH holiday actually worked
                    */

                        'ph_holiday_worked_days' =>
                        $phHolidayWorkedDays,

                        /*
                    | US holiday attendance
                    */

                        'us_holiday_present_days' =>
                        $usHolidayPresentDays,

                        /*
                    | Final absence count
                    */

                        'absences' => $absences,
                    ];
                }
            );


            /*
        |--------------------------------------------------------------------------
        | RESPONSE
        |--------------------------------------------------------------------------
        */

            return response()->json([
                'isSuccess' => true,
                'message' => 'Employees retrieved successfully.',
                'data' => $employeeData,
                'summary' => [
                    'total_working_days' => $totalWorkingDays,
                ],
            ], 200);
        } catch (\Exception $e) {

            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to retrieve employees.',
                'error' => $e->getMessage(),
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

                'payrolls' => $periods,

            ]);
        } catch (\Exception $e) {

            return response()->json([

                'isSuccess' => false,

                'message' =>
                'Failed to fetch payroll periods.',

                'error' =>
                $e->getMessage(),

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
                            ->select('id', 'payroll_period_id', 'employee_id', 'gross_pay', 'total_deductions', 'net_pay', 'remarks');
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

            //  Clone query for totals
            $totalsQuery = clone $query;

            //  Compute totals for all records (not just current page)
            $summary = [
                'total_gross'      => number_format($totalsQuery->sum('gross_pay'), 2),
                'total_deductions' => number_format($totalsQuery->sum('total_deductions'), 2),
                'total_net'        => number_format($totalsQuery->sum('net_pay'), 2),
            ];

            // Now paginate for display
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

            //  Clone query for totals
            $totalsQuery = clone $query;

            //  Compute summary for user’s record(s)
            $summary = [
                'total_gross'      => number_format($totalsQuery->sum('gross_pay'), 2),
                'total_deductions' => number_format($totalsQuery->sum('total_deductions'), 2),
                'total_net'        => number_format($totalsQuery->sum('net_pay'), 2),
            ];

            // Paginate (usually just one record per period per user, but still for consistency)
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


            $perPage = $request->input('per_page', 12);
            $search  = $request->input('search');

            $query = PayrollRecord::with([
                'payrollPeriod:id,period_name,pay_date,cutoff_start_date,cutoff_end_date',
                'employee:id,base_salary',
                'allowances.allowanceType:id,type_name',
                'deductions.benefitType:id,benefit_name',
                'deductions.loan.loanType:id,type_name',
            ])
                ->where('employee_id', $employeeId)
                ->where('is_archived', false)
                ->whereHas('payrollPeriod', function ($q) {
                    $q->where('status', 'processed');
                })
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
                    'record_id'              => $record->id,
                    'remarks'                => $record->remarks,
                    'period'                 => $record->payrollPeriod->period_name ?? 'N/A',
                    'period_range'           => ($record->payrollPeriod->cutoff_start_date ?? '') . ' - ' . ($record->payrollPeriod->cutoff_end_date ?? ''),

                    'daily_rate'             => number_format($record->daily_rate, 2),
                    'days_worked'            => $record->days_worked,
                    'overtime_hours'         => $record->overtime_hours,
                    'absences'               => $record->absences,

                    'gross_base'             => number_format($record->gross_base, 2),
                    'gross_pay'              => number_format($record->gross_pay, 2),
                    'night_diff_pay'         => number_format($record->night_diff_pay, 2),

                    'total_allowances'       => number_format($record->total_allowances ?? 0, 2),
                    'total_loan_deductions'  => number_format($record->total_loan_deductions ?? 0, 2),
                    'total_late_deductions'  => number_format($record->total_late_deductions ?? 0, 2),
                    'total_deductions'       => number_format($record->total_deductions, 2),

                    'net_pay'                => number_format($record->net_pay, 2),
                    'generated_at'           => $record->created_at->format('F d, Y'),

                    'allowances'             => $allowances,
                    'deductions'             => $deductions,

                    'basic_salary'           => number_format($record->employee->base_salary ?? 0, 2),
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
     *  Get individual employee payslip
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


            /*
        |--------------------------------------------------------------------------
        | Get Holidays Within Payroll Period
        |--------------------------------------------------------------------------
        */

            $holidays = collect();

            if ($record->payrollPeriod) {

                $holidays = Holiday::whereBetween('holiday_date', [
                    $record->payrollPeriod->cutoff_start_date,
                    $record->payrollPeriod->cutoff_end_date,
                ])
                    ->orderBy('holiday_date', 'asc')
                    ->get();
            }


            /*
        |--------------------------------------------------------------------------
        | Map Holidays
        |--------------------------------------------------------------------------
        */

            $holidayData = $holidays->map(function ($holiday) {

                return [
                    'holiday_date' => Carbon::parse(
                        $holiday->holiday_date
                    )->format('F d, Y'),

                    'holiday_name' => $holiday->holiday_name
                        ?? 'Holiday',

                    'holiday_type' => $holiday->holiday_type
                        ?? 'Holiday',
                ];
            })->values();


            /*
        |--------------------------------------------------------------------------
        | Map Allowances
        |--------------------------------------------------------------------------
        */

            $allowances = $record->allowances->map(function ($a) {

                return [
                    'allowance_type' => $a->allowanceType->type_name
                        ?? 'Other Allowance',

                    'allowance_amount' => number_format(
                        $a->allowance_amount,
                        2
                    ),
                ];
            });


            /*
        |--------------------------------------------------------------------------
        | Map Deductions
        |--------------------------------------------------------------------------
        */

            $deductions = $record->deductions->map(function ($ded) {

                if ($ded->loan_id) {

                    return [
                        'deduction_type' => 'Loan Payment',

                        'loan_name' => $ded->loan->loanType->type_name
                            ?? 'Loan',

                        'deduction_amount' => number_format(
                            $ded->deduction_amount,
                            2
                        ),
                    ];
                } elseif ($ded->benefit_type_id) {

                    return [
                        'deduction_type' => $ded->benefitType->benefit_name
                            ?? 'Other Deduction',

                        'deduction_amount' => number_format(
                            $ded->deduction_amount,
                            2
                        ),
                    ];
                }

                return [
                    'deduction_type' => $ded->deduction_name
                        ?? 'Other Deduction',

                    'deduction_amount' => number_format(
                        $ded->deduction_amount,
                        2
                    ),
                ];
            });


            /*
        |--------------------------------------------------------------------------
        | Return Payslip
        |--------------------------------------------------------------------------
        */

            return response()->json([

                'isSuccess' => true,

                'payslip' => [

                    /*
                |--------------------------------------------------------------------------
                | Employee
                |--------------------------------------------------------------------------
                */

                    'employee_name' =>
                    "{$record->employee->first_name} {$record->employee->last_name}",


                    /*
                |--------------------------------------------------------------------------
                | Payroll Period
                |--------------------------------------------------------------------------
                */

                    'period' =>
                    $record->payrollPeriod->period_name ?? 'N/A',

                    'period_range' => ($record->payrollPeriod->cutoff_start_date ?? '') .
                        ' - ' .
                        ($record->payrollPeriod->cutoff_end_date ?? ''),


                    /*
                |--------------------------------------------------------------------------
                | Basic Payroll Information
                |--------------------------------------------------------------------------
                */

                    'remarks' => $record->remarks,

                    'daily_rate' => number_format(
                        $record->daily_rate,
                        2
                    ),

                    'days_worked' => number_format(
                        $record->days_worked,
                        2
                    ),

                    'overtime_hours' => number_format(
                        $record->overtime_hours,
                        2
                    ),

                    'absences' => $record->absences,


                    /*
                |--------------------------------------------------------------------------
                | Base Pay
                |--------------------------------------------------------------------------
                */

                    'base_pay' => number_format(
                        $record->daily_rate * $record->days_worked,
                        2
                    ),

                    'gross_base' => number_format(
                        $record->gross_base,
                        2
                    ),

                    'gross_pay' => number_format(
                        $record->gross_pay,
                        2
                    ),


                    /*
                |--------------------------------------------------------------------------
                | Night Differential
                |--------------------------------------------------------------------------
                */

                    'night_diff_pay' => number_format(
                        $record->night_diff_pay ?? 0,
                        2
                    ),


                    /*
                |--------------------------------------------------------------------------
                | Holidays
                |--------------------------------------------------------------------------
                */

                    'holidays' => $holidayData,

                    'total_holidays' => $holidayData->count(),


                    /*
                |--------------------------------------------------------------------------
                | Allowances
                |--------------------------------------------------------------------------
                */

                    'allowances' => $allowances,

                    'total_allowances' => number_format(
                        $record->total_allowances ?? 0,
                        2
                    ),


                    /*
                |--------------------------------------------------------------------------
                | Deductions
                |--------------------------------------------------------------------------
                */

                    'deductions' => $deductions,

                    'total_loan_deductions' => number_format(
                        $record->total_loan_deductions ?? 0,
                        2
                    ),

                    'total_late_deductions' => number_format(
                        $record->total_late_deductions ?? 0,
                        2
                    ),

                    'total_deductions' => number_format(
                        $record->total_deductions,
                        2
                    ),


                    /*
                |--------------------------------------------------------------------------
                | Net Pay
                |--------------------------------------------------------------------------
                */

                    'net_pay' => number_format(
                        $record->net_pay,
                        2
                    ),


                    /*
                |--------------------------------------------------------------------------
                | Generated At
                |--------------------------------------------------------------------------
                */

                    'generated_at' => $record->created_at
                        ? $record->created_at->format(
                            'F d, Y h:i A'
                        )
                        : null,
                ],
            ]);
        } catch (\Exception $e) {

            Log::error(
                'Error generating payslip: ' . $e->getMessage()
            );

            return response()->json([

                'isSuccess' => false,

                'message' => 'Failed to generate payslip.',

                'error' => $e->getMessage(),

            ], 500);
        }
    }






    public function getMyPayslips(Request $request, $recordId)
    {
        try {

            $employee = auth()->user();

            if (!$employee) {
                return response()->json([
                    'isSuccess' => false,
                    'message'   => 'Unauthorized.',
                ], 403);
            }

            $record = PayrollRecord::with([
                'payrollPeriod',
                'employee:id,base_salary',
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

            /*
        |--------------------------------------------------------------------------
        | Get Payroll Period
        |--------------------------------------------------------------------------
        */

            $payrollPeriod = $record->payrollPeriod;


            /*
        |--------------------------------------------------------------------------
        | Get Holidays Within Payroll Period
        |--------------------------------------------------------------------------
        */

            $holidays = collect();

            if ($payrollPeriod) {

                $holidays = Holiday::whereBetween('holiday_date', [
                    $payrollPeriod->cutoff_start_date,
                    $payrollPeriod->cutoff_end_date,
                ])
                    ->orderBy('holiday_date', 'asc')
                    ->get();
            }


            /*
        |--------------------------------------------------------------------------
        | Map Allowances
        |--------------------------------------------------------------------------
        */

            $allowances = $record->allowances->map(function ($a) {

                return [
                    'allowance_type'   => $a->allowanceType->type_name
                        ?? 'Other Allowance',

                    'allowance_amount' => number_format(
                        $a->allowance_amount,
                        2
                    ),
                ];
            });


            /*
        |--------------------------------------------------------------------------
        | Map Deductions
        |--------------------------------------------------------------------------
        */

            $deductions = $record->deductions->map(function ($ded) {

                /*
            |--------------------------------------------------------------------------
            | Loan Deduction
            |--------------------------------------------------------------------------
            */

                if ($ded->loan_id) {

                    return [
                        'deduction_type'   => 'Loan Payment',

                        'loan_name'        => $ded->loan->loanType->type_name
                            ?? 'Loan',

                        'deduction_amount' => number_format(
                            $ded->deduction_amount,
                            2
                        ),
                    ];
                }


                /*
            |--------------------------------------------------------------------------
            | Benefit Deduction
            |--------------------------------------------------------------------------
            */ elseif ($ded->benefit_type_id) {

                    return [
                        'deduction_type'   => $ded->benefitType->benefit_name
                            ?? 'Other Deduction',

                        'deduction_amount' => number_format(
                            $ded->deduction_amount,
                            2
                        ),
                    ];
                }


                /*
            |--------------------------------------------------------------------------
            | Other Deduction
            |--------------------------------------------------------------------------
            */

                return [
                    'deduction_type'   => $ded->deduction_name
                        ?? 'Other Deduction',

                    'deduction_amount' => number_format(
                        $ded->deduction_amount,
                        2
                    ),
                ];
            });


            /*
        |--------------------------------------------------------------------------
        | Map Holidays
        |--------------------------------------------------------------------------
        */

            $holidayData = $holidays->map(function ($holiday) {

                return [
                    'holiday_date' => Carbon::parse(
                        $holiday->holiday_date
                    )->format('F d, Y'),

                    'holiday_name' => $holiday->holiday_name
                        ?? 'Holiday',

                    'holiday_type' => $holiday->holiday_type
                        ?? 'Holiday',
                ];
            })->values();


            /*
        |--------------------------------------------------------------------------
        | Night Differential
        |--------------------------------------------------------------------------
        |
        | The night_diff_pay value is already stored in payroll_records.
        |
        */

            $nightDiffPay = $record->night_diff_pay ?? 0;


            /*
        |--------------------------------------------------------------------------
        | Return Payslip
        |--------------------------------------------------------------------------
        */

            return response()->json([

                'isSuccess' => true,

                'payslip' => [

                    /*
                |--------------------------------------------------------------------------
                | Employee Information
                |--------------------------------------------------------------------------
                */

                    'employee_name' => "{$employee->first_name} {$employee->last_name}",


                    /*
                |--------------------------------------------------------------------------
                | Payroll Period
                |--------------------------------------------------------------------------
                */

                    'period' => $payrollPeriod->period_name ?? 'N/A',

                    'cutoff_start_date' => $payrollPeriod
                        ? Carbon::parse(
                            $payrollPeriod->cutoff_start_date
                        )->format('F d, Y')
                        : null,

                    'cutoff_end_date' => $payrollPeriod
                        ? Carbon::parse(
                            $payrollPeriod->cutoff_end_date
                        )->format('F d, Y')
                        : null,


                    /*
                |--------------------------------------------------------------------------
                | Salary
                |--------------------------------------------------------------------------
                */

                    'base_salary' => number_format(
                        $employee->base_salary ?? 0,
                        2
                    ),

                    'daily_rate' => number_format(
                        $record->daily_rate,
                        2
                    ),

                    'days_worked' => number_format(
                        $record->days_worked,
                        2
                    ),

                    'gross_base' => number_format(
                        $record->gross_base,
                        2
                    ),


                    /*
                |--------------------------------------------------------------------------
                | Night Differential
                |--------------------------------------------------------------------------
                */

                    'night_diff_pay' => number_format(
                        $nightDiffPay,
                        2
                    ),


                    /*
                |--------------------------------------------------------------------------
                | Gross Pay
                |--------------------------------------------------------------------------
                */

                    'gross_pay' => number_format(
                        $record->gross_pay,
                        2
                    ),


                    /*
                |--------------------------------------------------------------------------
                | Holidays
                |--------------------------------------------------------------------------
                */

                    'holidays' => $holidayData,

                    'total_holidays' => $holidayData->count(),


                    /*
                |--------------------------------------------------------------------------
                | Allowances
                |--------------------------------------------------------------------------
                */

                    'allowances' => $allowances,

                    'total_allowances' => number_format(
                        $record->allowances->sum('allowance_amount'),
                        2
                    ),


                    /*
                |--------------------------------------------------------------------------
                | Deductions
                |--------------------------------------------------------------------------
                */

                    'deductions' => $deductions,


                    /*
                |--------------------------------------------------------------------------
                | Late Deductions
                |--------------------------------------------------------------------------
                */

                    'total_late_deductions' => number_format(
                        $record->total_late_deductions ?? 0,
                        2
                    ),


                    /*
                |--------------------------------------------------------------------------
                | Total Deductions
                |--------------------------------------------------------------------------
                */

                    'total_deductions' => number_format(
                        $record->total_deductions,
                        2
                    ),


                    /*
                |--------------------------------------------------------------------------
                | Net Pay
                |--------------------------------------------------------------------------
                */

                    'net_pay' => number_format(
                        $record->net_pay,
                        2
                    ),


                    /*
                |--------------------------------------------------------------------------
                | Remarks
                |--------------------------------------------------------------------------
                */

                    'remarks' => $record->remarks,


                    /*
                |--------------------------------------------------------------------------
                | Generated Date
                |--------------------------------------------------------------------------
                */

                    'generated_at' => $record->created_at
                        ? $record->created_at->format(
                            'F d, Y h:i A'
                        )
                        : null,
                ],
            ]);
        } catch (\Exception $e) {

            Log::error(
                'Error fetching employee payslip: ' . $e->getMessage()
            );

            return response()->json([

                'isSuccess' => false,

                'message' => 'Failed to fetch payslip.',

                'error' => $e->getMessage(),

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

    public function createThirteenthMonthPeriod(Request $request)
    {
        $request->validate([
            'period_name'   => 'required|string',
            'start_date'    => 'required|date',
            'end_date'      => 'required|date|after_or_equal:start_date',
            'employees'     => 'required|array|min:1',
            'employees.*.employee_id' => 'required|integer|exists:employees,id',
        ]);

        DB::beginTransaction();

        try {

            // ========================================
            // CREATE THE PERIOD FIRST
            // ========================================
            $period = ThirteenthMonthPeriod::create([
                'period_name' => $request->period_name,
                'start_date'  => $request->start_date,
                'end_date'    => $request->end_date,
                'is_locked'   => false,
            ]);

            foreach ($request->employees as $empData) {

                $employeeId = $empData['employee_id'];

                // ========================================
                // SUM TOTAL BASIC PAY FROM PAYROLL RECORDS
                // ========================================
                $totalBasicSalary = PayrollRecord::where('employee_id', $employeeId)
                    ->whereBetween('created_at', [
                        $period->start_date,
                        $period->end_date
                    ])
                    ->sum('gross_base');

                // 13th month formula
                $amount = round($totalBasicSalary / 12, 2);

                // ========================================
                // INSERT 13TH MONTH PAY ENTRY
                // ========================================
                ThirteenthMonth::updateOrCreate(
                    [
                        'period_id'   => $period->id,
                        'employee_id' => $employeeId,
                    ],
                    [
                        'amount'      => $amount,
                        'remarks'     => null,
                        'is_archived' => false,
                    ]
                );
            }

            DB::commit();

            return response()->json([
                'isSuccess' => true,
                'message'   => '13th month period created and pays generated successfully.',
                'period'    => $period,
            ], 201);
        } catch (\Exception $e) {

            DB::rollBack();
            Log::error('13th month generation failed: ' . $e->getMessage());

            return response()->json([
                'isSuccess' => false,
                'message'   => 'Failed to generate 13th month.',
                'error'     => $e->getMessage(),
            ], 500);
        }
    }

    public function getThirteenthMonthPeriods()
    {
        $periods = ThirteenthMonthPeriod::orderBy('created_at', 'desc')->get();

        return response()->json([
            'isSuccess' => true,
            'data' => $periods
        ]);
    }

    public function getPaysByPeriod($periodId, Request $request)
    {
        $perPage = $request->input('per_page', 10);

        $pays = ThirteenthMonth::with([
            'employee:id,first_name,last_name,employee_id,department_id,position_id',
            'employee.department:id,department_name',
            'employee.position:id,position_name'
        ])
            ->where('period_id', $periodId)
            ->where('is_archived', false)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        // Format response to include department/position names directly
        $data = $pays->map(function ($pay) {
            return [
                'id' => $pay->id,
                'employee_id' => $pay->employee->employee_id,
                'employee_name' => $pay->employee->first_name . ' ' . $pay->employee->last_name,
                'department' => $pay->employee->department?->department_name,
                'position'   => $pay->employee->position?->position_name,
                'amount' => $pay->amount,
                'remarks' => $pay->remarks,
                'created_at' => $pay->created_at,
            ];
        });

        return response()->json([
            'isSuccess' => true,
            'data' => $data,
            'pagination' => [
                'total' => $pays->total(),
                'per_page' => $pays->perPage(),
                'current_page' => $pays->currentPage(),
                'last_page' => $pays->lastPage(),
            ],
        ]);
    }
}
