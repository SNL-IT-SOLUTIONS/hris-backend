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
        ]);

        DB::beginTransaction();

        try {
            $period = PayrollPeriod::create([
                'period_name' => $validated['period_name'],
                'pay_date' => $validated['pay_date'],
                'cutoff_start_date' => $validated['cutoff_start_date'],
                'cutoff_end_date' => $validated['cutoff_end_date'],
                'status' => 'processed',
            ]);

            $employees = Employee::where('is_archived', 0)->get();

            $totalPayroll = 0;
            $totalAllowances = 0;
            $totalDeductions = 0;

            foreach ($employees as $employee) {

                /*
            |--------------------------------------------------------------------------
            | BASIC SALARY
            |--------------------------------------------------------------------------
            */

                $dailyRate = (float) $employee->base_salary;
                $hourlyRate = $dailyRate / 8;

                /*
            |--------------------------------------------------------------------------
            | CUTOFF DAYS
            |--------------------------------------------------------------------------
            */

                $cutoffStart = Carbon::parse($validated['cutoff_start_date']);
                $cutoffEnd = Carbon::parse($validated['cutoff_end_date']);

                $cutoffDays = 0;

                for (
                    $date = $cutoffStart->copy();
                    $date->lte($cutoffEnd);
                    $date->addDay()
                ) {
                    if (!$date->isWeekend()) {
                        $cutoffDays++;
                    }
                }

                /*
            |--------------------------------------------------------------------------
            | ATTENDANCE
            |--------------------------------------------------------------------------
            */

                $attendances = Attendance::where('employee_id', $employee->id)
                    ->whereBetween('attendance_date', [
                        $validated['cutoff_start_date'],
                        $validated['cutoff_end_date']
                    ])
                    ->get();

                $normalWorkedDays = $attendances
                    ->filter(function ($attendance) {
                        return !$attendance->is_holiday;
                    })
                    ->count();

                /*
            |--------------------------------------------------------------------------
            | OVERTIME
            |--------------------------------------------------------------------------
            */

                $overtimeHours = (float) $attendances->sum('overtime_hours');

                $overtimePay = $overtimeHours * $hourlyRate * 1.25;

                /*
            |--------------------------------------------------------------------------
            | NIGHT DIFFERENTIAL
            |--------------------------------------------------------------------------
            */

                $nightHours = (float) $attendances->sum('night_hours');

                $nightRate = 10;

                $nightDifferential = $nightHours * $hourlyRate * ($nightRate / 100);

                /*
            |--------------------------------------------------------------------------
            | HOLIDAYS
            |--------------------------------------------------------------------------
            */

                $phHolidayWorkedDays = 0;
                $holidayPay = 0;

                foreach ($attendances as $attendance) {

                    if (!$attendance->is_holiday) {
                        continue;
                    }

                    $holiday = Holiday::whereDate(
                        'holiday_date',
                        $attendance->attendance_date
                    )->first();

                    if (!$holiday) {
                        continue;
                    }

                    /*
                |--------------------------------------------------------------------------
                | US HOLIDAYS ARE UNPAID
                |--------------------------------------------------------------------------
                */

                    if (strtoupper($holiday->country ?? '') === 'US') {
                        continue;
                    }

                    /*
                |--------------------------------------------------------------------------
                | PH HOLIDAY
                |--------------------------------------------------------------------------
                */

                    $phHolidayWorkedDays++;

                    $holidayRate = (float) ($holiday->rate ?? 100);

                    $holidayPay += $dailyRate * ($holidayRate / 100);
                }

                /*
            |--------------------------------------------------------------------------
            | APPROVED PAID LEAVES
            |--------------------------------------------------------------------------
            */

                $paidLeaveDays = 0;

                $approvedLeaves = EmployeeLeave::where('employee_id', $employee->id)
                    ->where('status', 'Approved')
                    ->where(function ($query) use ($validated) {
                        $query->whereBetween('start_date', [
                            $validated['cutoff_start_date'],
                            $validated['cutoff_end_date']
                        ])
                            ->orWhereBetween('end_date', [
                                $validated['cutoff_start_date'],
                                $validated['cutoff_end_date']
                            ])
                            ->orWhere(function ($q) use ($validated) {
                                $q->where('start_date', '<=', $validated['cutoff_start_date'])
                                    ->where('end_date', '>=', $validated['cutoff_end_date']);
                            });
                    })
                    ->get();

                foreach ($approvedLeaves as $leave) {

                    $leaveStart = Carbon::parse($leave->start_date)
                        ->greaterThan($cutoffStart)
                        ? Carbon::parse($leave->start_date)
                        : $cutoffStart->copy();

                    $leaveEnd = Carbon::parse($leave->end_date)
                        ->lessThan($cutoffEnd)
                        ? Carbon::parse($leave->end_date)
                        : $cutoffEnd->copy();

                    for (
                        $date = $leaveStart->copy();
                        $date->lte($leaveEnd);
                        $date->addDay()
                    ) {

                        if ($date->isWeekend()) {
                            continue;
                        }

                        /*
                    |--------------------------------------------------------------------------
                    | Do not count holiday as paid leave
                    |--------------------------------------------------------------------------
                    */

                        $holiday = Holiday::whereDate(
                            'holiday_date',
                            $date->format('Y-m-d')
                        )->first();

                        if ($holiday) {
                            continue;
                        }

                        /*
                    |--------------------------------------------------------------------------
                    | Do not count a day with attendance as leave
                    |--------------------------------------------------------------------------
                    */

                        $hasAttendance = $attendances->contains(function ($attendance) use ($date) {
                            return Carbon::parse($attendance->attendance_date)
                                ->isSameDay($date);
                        });

                        if ($hasAttendance) {
                            continue;
                        }

                        $paidLeaveDays++;
                    }
                }

                /*
            |--------------------------------------------------------------------------
            | ABSENCES
            |--------------------------------------------------------------------------
            */

                $absences = max(
                    $cutoffDays
                        - $normalWorkedDays
                        - $paidLeaveDays
                        - $phHolidayWorkedDays,
                    0
                );

                /*
            |--------------------------------------------------------------------------
            | LATE DEDUCTION
            |--------------------------------------------------------------------------
            */

                $lateDeduction = (float) $attendances->sum('late_deduction');

                /*
            |--------------------------------------------------------------------------
            | BASIC PAY
            |--------------------------------------------------------------------------
            */

                $basicPay = $normalWorkedDays * $dailyRate;

                /*
            |--------------------------------------------------------------------------
            | ALLOWANCES
            |--------------------------------------------------------------------------
            */

                $allowanceRecords = [];
                $employeeAllowanceTotal = 0;

                $employeeAllowances = DB::table('employee_allowance')
                    ->where('employee_id', $employee->id)
                    ->get();

                foreach ($employeeAllowances as $employeeAllowance) {

                    $allowanceAmount = (float) $employeeAllowance->amount;

                    /*
                |--------------------------------------------------------------------------
                | Semi-monthly allowance
                |--------------------------------------------------------------------------
                */

                    $semiMonthlyAmount = $allowanceAmount / 2;

                    /*
                |--------------------------------------------------------------------------
                | Perfect Attendance Allowance
                | Type ID 12 = full amount only when no absence and no late
                |--------------------------------------------------------------------------
                */

                    if ((int) $employeeAllowance->allowance_type_id === 12) {

                        if ($absences === 0 && $lateDeduction <= 0) {
                            $computedAllowance = $allowanceAmount;
                        } else {
                            $computedAllowance = 0;
                        }
                    } else {

                        $computedAllowance = $semiMonthlyAmount;
                    }

                    $allowanceRecords[] = [
                        'allowance_type_id' => $employeeAllowance->allowance_type_id,
                        'amount' => $computedAllowance,
                    ];

                    $employeeAllowanceTotal += $computedAllowance;
                }

                /*
            |--------------------------------------------------------------------------
            | BENEFITS
            |--------------------------------------------------------------------------
            */

                $benefitRecords = [];
                $employeeBenefitTotal = 0;

                $employeeBenefits = DB::table('employee_benefit')
                    ->where('employee_id', $employee->id)
                    ->get();

                foreach ($employeeBenefits as $employeeBenefit) {

                    $benefitType = BenefitType::find($employeeBenefit->benefit_type_id);

                    if (!$benefitType) {
                        continue;
                    }

                    $benefitAmount = (float) ($benefitType->amount ?? 0);

                    $semiMonthlyBenefit = $benefitAmount / 2;

                    $benefitRecords[] = [
                        'benefit_type_id' => $employeeBenefit->benefit_type_id,
                        'amount' => $semiMonthlyBenefit,
                    ];

                    $employeeBenefitTotal += $semiMonthlyBenefit;
                }

                /*
            |--------------------------------------------------------------------------
            | LOANS
            |--------------------------------------------------------------------------
            */

                $loanRecords = [];
                $employeeLoanTotal = 0;

                $loans = Loan::where('employee_id', $employee->id)
                    ->where(function ($query) {
                        $query->whereNull('status')
                            ->orWhereIn('status', ['active', 'Active', 'ongoing', 'Ongoing']);
                    })
                    ->get();

                foreach ($loans as $loan) {

                    $monthlyAmortization = (float) (
                        $loan->monthly_amortization
                        ?? $loan->monthly_payment
                        ?? $loan->amount
                        ?? 0
                    );

                    $deductionAmount = $monthlyAmortization / 2;

                    /*
                |--------------------------------------------------------------------------
                | Do not deduct more than remaining balance
                |--------------------------------------------------------------------------
                */

                    if (
                        isset($loan->remaining_balance)
                        && $loan->remaining_balance !== null
                    ) {
                        $remainingBalance = (float) $loan->remaining_balance;

                        $deductionAmount = min(
                            $deductionAmount,
                            max($remainingBalance, 0)
                        );
                    }

                    if ($deductionAmount <= 0) {
                        continue;
                    }

                    $loanRecords[] = [
                        'loan' => $loan,
                        'amount' => $deductionAmount,
                    ];

                    $employeeLoanTotal += $deductionAmount;
                }

                /*
            |--------------------------------------------------------------------------
            | TOTAL DEDUCTIONS
            |--------------------------------------------------------------------------
            */

                $totalEmployeeDeductions =
                    $employeeBenefitTotal
                    + $employeeLoanTotal
                    + $lateDeduction;

                /*
            |--------------------------------------------------------------------------
            | GROSS PAY
            |--------------------------------------------------------------------------
            */

                $grossPay =
                    $basicPay
                    + $overtimePay
                    + $nightDifferential
                    + $holidayPay
                    + $employeeAllowanceTotal;

                /*
            |--------------------------------------------------------------------------
            | NET PAY
            |--------------------------------------------------------------------------
            */

                $netPay = $grossPay - $totalEmployeeDeductions;

                /*
            |--------------------------------------------------------------------------
            | PAYROLL RECORD
            |--------------------------------------------------------------------------
            */

                $payrollRecord = PayrollRecord::create([
                    'payroll_period_id' => $period->id,
                    'employee_id' => $employee->id,

                    'base_salary' => $dailyRate,

                    'days_worked' => $normalWorkedDays,
                    'absences' => $absences,

                    'overtime_hours' => $overtimeHours,
                    'overtime_pay' => $overtimePay,

                    'night_hours' => $nightHours,
                    'night_differential' => $nightDifferential,

                    'holiday_pay' => $holidayPay,

                    'total_allowances' => $employeeAllowanceTotal,

                    'total_deductions' => $totalEmployeeDeductions,

                    'late_deduction' => $lateDeduction,

                    'gross_pay' => $grossPay,
                    'net_pay' => $netPay,

                    'status' => 'processed',
                ]);

                /*
            |--------------------------------------------------------------------------
            | SAVE BENEFIT DEDUCTIONS
            |--------------------------------------------------------------------------
            */

                foreach ($benefitRecords as $benefitRecord) {

                    PayrollDeduction::create([
                        'payroll_record_id' => $payrollRecord->id,
                        'benefit_type_id' => $benefitRecord['benefit_type_id'],

                        // CORRECT DATABASE COLUMN
                        'deduction_amount' => $benefitRecord['amount'],
                    ]);
                }

                /*
            |--------------------------------------------------------------------------
            | SAVE LOAN DEDUCTIONS
            |--------------------------------------------------------------------------
            */

                foreach ($loanRecords as $loanRecord) {

                    $loan = $loanRecord['loan'];
                    $deductionAmount = $loanRecord['amount'];

                    PayrollDeduction::create([
                        'payroll_record_id' => $payrollRecord->id,
                        'loan_id' => $loan->id,

                        // CORRECT DATABASE COLUMN
                        'deduction_amount' => $deductionAmount,
                    ]);

                    /*
                |--------------------------------------------------------------------------
                | UPDATE LOAN BALANCE
                |--------------------------------------------------------------------------
                */

                    if (
                        isset($loan->remaining_balance)
                        && $loan->remaining_balance !== null
                    ) {

                        $newBalance =
                            (float) $loan->remaining_balance
                            - $deductionAmount;

                        if ($newBalance <= 0) {

                            $loan->remaining_balance = 0;
                            $loan->status = 'paid';
                        } else {

                            $loan->remaining_balance = $newBalance;
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
                        'payroll_record_id' => $payrollRecord->id,
                        'allowance_type_id' => $allowanceRecord['allowance_type_id'],

                        // CORRECT DATABASE COLUMN
                        'allowance_amount' => $allowanceRecord['amount'],
                    ]);
                }

                /*
            |--------------------------------------------------------------------------
            | TOTALS
            |--------------------------------------------------------------------------
            */

                $totalPayroll += $netPay;
                $totalAllowances += $employeeAllowanceTotal;
                $totalDeductions += $totalEmployeeDeductions;
            }

            /*
        |--------------------------------------------------------------------------
        | UPDATE PAYROLL PERIOD TOTALS
        |--------------------------------------------------------------------------
        */

            $period->update([
                'total_payroll' => $totalPayroll,
                'total_allowances' => $totalAllowances,
                'total_deductions' => $totalDeductions,
            ]);

            DB::commit();

            return response()->json([
                'isSuccess' => true,
                'message' => 'Payroll period created successfully.',
                'data' => $period->load([
                    'payrollRecords.employee',
                    'payrollRecords.allowances',
                    'payrollRecords.deductions',
                ]),
                'summary' => [
                    'total_payroll' => $totalPayroll,
                    'total_allowances' => $totalAllowances,
                    'total_deductions' => $totalDeductions,
                    'employees_processed' => $employees->count(),
                ],
            ], 201);
        } catch (\Throwable $e) {

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

                        'base_salary' => $employee->base_salary,

                        /*
    |--------------------------------------------------------------------------
    | Total paid/present days
    |--------------------------------------------------------------------------
    */
                        'days_worked' => $displayDaysWorked,

                        /*
    |--------------------------------------------------------------------------
    | Actual physical attendance
    |--------------------------------------------------------------------------
    */
                        'actual_worked_days' => $actualWorkedDays,

                        /*
    |--------------------------------------------------------------------------
    | Approved paid leave
    |--------------------------------------------------------------------------
    */
                        'paid_leave_days' => $paidLeaveDays,

                        /*
    |--------------------------------------------------------------------------
    | PH holiday actually worked
    |--------------------------------------------------------------------------
    */
                        'ph_holiday_worked_days' => $phHolidayWorkedDays,

                        /*
    |--------------------------------------------------------------------------
    | US holiday attendance
    |--------------------------------------------------------------------------
    */
                        'us_holiday_present_days' => $usHolidayPresentDays,

                        /*
    |--------------------------------------------------------------------------
    | Final absence count
    |--------------------------------------------------------------------------
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
                'employees' => $employeeData,
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

            /*
        |--------------------------------------------------------------------------
        | Get Payroll Period
        |--------------------------------------------------------------------------
        */
            $payrollPeriod = PayrollPeriod::where('id', $id)
                ->where('is_archived', false)
                ->first();

            if (!$payrollPeriod) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'Payroll period not found.',
                ], 404);
            }

            $perPage = $request->input('per_page', 5);
            $search = $request->input('search');

            /*
        |--------------------------------------------------------------------------
        | Payroll Records
        |--------------------------------------------------------------------------
        */
            $query = PayrollRecord::with([
                'employee:id,employee_id,first_name,last_name,email,department_id,position_id,base_salary',

                'employee.department:id,department_name',

                'employee.position:id,position_name',

                'employee.leaves' => function ($q) use ($payrollPeriod) {

                    $q->where('status', 'Approved')
                        ->where('is_archived', false)

                        /*
                    |--------------------------------------------------------------------------
                    | Get leaves that overlap this payroll period
                    |--------------------------------------------------------------------------
                    */
                        ->where('start_date', '<=', $payrollPeriod->cutoff_end_date)
                        ->where('end_date', '>=', $payrollPeriod->cutoff_start_date);
                },

                'employee.leaves.leaveType:id,leave_name',

                'deductions.benefitType:id,benefit_name',

                'deductions.loan.loanType:id,type_name',

                'allowances.allowanceType:id,type_name',
            ])
                ->where('payroll_period_id', $id)
                ->where('is_archived', false);

            /*
        |--------------------------------------------------------------------------
        | Search Employee
        |--------------------------------------------------------------------------
        */
            if (!empty($search)) {

                $query->whereHas('employee', function ($q) use ($search) {

                    $q->where(function ($employeeQuery) use ($search) {

                        $employeeQuery
                            ->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('employee_id', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
                });
            }

            /*
        |--------------------------------------------------------------------------
        | Summary
        |--------------------------------------------------------------------------
        */
            $totalsQuery = clone $query;

            $summary = [
                'total_gross_base' => number_format(
                    $totalsQuery->sum('gross_base'),
                    2
                ),

                'total_allowances' => number_format(
                    $totalsQuery->sum('total_allowances'),
                    2
                ),

                'total_overtime_hours' => number_format(
                    $totalsQuery->sum('overtime_hours'),
                    2
                ),

                'total_night_diff_pay' => number_format(
                    $totalsQuery->sum('night_diff_pay'),
                    2
                ),

                'total_holiday_pay' => number_format(
                    $totalsQuery->sum('holiday_pay'),
                    2
                ),

                'total_loan_deductions' => number_format(
                    $totalsQuery->sum('total_loan_deductions'),
                    2
                ),

                'total_late_deductions' => number_format(
                    $totalsQuery->sum('total_late_deductions'),
                    2
                ),

                'total_deductions' => number_format(
                    $totalsQuery->sum('total_deductions'),
                    2
                ),

                'total_gross' => number_format(
                    $totalsQuery->sum('gross_pay'),
                    2
                ),

                'total_net' => number_format(
                    $totalsQuery->sum('net_pay'),
                    2
                ),
            ];

            /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */
            $payrollDetails = $query
                ->orderBy('id', 'desc')
                ->paginate($perPage);

            /*
        |--------------------------------------------------------------------------
        | Format Payroll Details
        |--------------------------------------------------------------------------
        */
            $data = collect($payrollDetails->items())->map(function ($record) use ($payrollPeriod) {

                $leaves = $record->employee?->leaves ?? collect();

                /*
            |--------------------------------------------------------------------------
            | Calculate leave days that actually fall inside
            | the payroll cutoff.
            |--------------------------------------------------------------------------
            */
                $leaveItems = $leaves->map(function ($leave) use ($payrollPeriod) {

                    $leaveStart = \Carbon\Carbon::parse($leave->start_date);
                    $leaveEnd = \Carbon\Carbon::parse($leave->end_date);

                    $cutoffStart = \Carbon\Carbon::parse(
                        $payrollPeriod->cutoff_start_date
                    );

                    $cutoffEnd = \Carbon\Carbon::parse(
                        $payrollPeriod->cutoff_end_date
                    );

                    /*
                |--------------------------------------------------------------------------
                | Get the overlapping date range
                |--------------------------------------------------------------------------
                */
                    $effectiveStart = $leaveStart->greaterThan($cutoffStart)
                        ? $leaveStart
                        : $cutoffStart;

                    $effectiveEnd = $leaveEnd->lessThan($cutoffEnd)
                        ? $leaveEnd
                        : $cutoffEnd;

                    /*
                |--------------------------------------------------------------------------
                | Calculate days within payroll period
                |--------------------------------------------------------------------------
                */
                    $daysInPayroll = 0;

                    if ($effectiveStart->lessThanOrEqualTo($effectiveEnd)) {
                        $daysInPayroll =
                            $effectiveStart->diffInDays($effectiveEnd) + 1;
                    }

                    return [
                        'id' => $leave->id,

                        'leave_type_id' =>
                        $leave->leave_type_id,

                        'leave_type' =>
                        $leave->leaveType?->leave_name,

                        'start_date' =>
                        $leave->start_date,

                        'end_date' =>
                        $leave->end_date,

                        'total_days' =>
                        $leave->total_days,

                        'days_in_payroll_period' =>
                        $daysInPayroll,

                        'reason' =>
                        $leave->reason,

                        'status' =>
                        $leave->status,

                        'is_paid' =>
                        (bool) $leave->is_paid,
                    ];
                });

                /*
            |--------------------------------------------------------------------------
            | Paid / Unpaid Leave Totals
            |--------------------------------------------------------------------------
            */
                $paidLeaveDays = $leaveItems
                    ->where('is_paid', true)
                    ->sum('days_in_payroll_period');

                $unpaidLeaveDays = $leaveItems
                    ->where('is_paid', false)
                    ->sum('days_in_payroll_period');

                $totalLeaveDays = $leaveItems
                    ->sum('days_in_payroll_period');

                return [

                    /*
                |--------------------------------------------------------------------------
                | Payroll Record
                |--------------------------------------------------------------------------
                */
                    'id' => $record->id,

                    /*
                |--------------------------------------------------------------------------
                | Employee
                |--------------------------------------------------------------------------
                */
                    'employee' => [
                        'id' =>
                        $record->employee?->id,

                        'employee_id' =>
                        $record->employee?->employee_id,

                        'first_name' =>
                        $record->employee?->first_name,

                        'last_name' =>
                        $record->employee?->last_name,

                        'email' =>
                        $record->employee?->email,

                        'department' =>
                        $record->employee?->department?->department_name,

                        'position' =>
                        $record->employee?->position?->position_name,

                        'base_salary' =>
                        $record->employee?->base_salary,
                    ],

                    /*
                |--------------------------------------------------------------------------
                | Attendance
                |--------------------------------------------------------------------------
                */
                    'attendance' => [
                        'daily_rate' =>
                        $record->daily_rate,

                        'days_worked' =>
                        $record->days_worked,

                        'absences' =>
                        $record->absences,

                        'total_late_minutes' =>
                        $record->total_late_minutes,

                        'overtime_hours' =>
                        $record->overtime_hours,
                    ],

                    /*
                |--------------------------------------------------------------------------
                | Leave
                |--------------------------------------------------------------------------
                */
                    'leave' => [
                        'total_days' =>
                        $totalLeaveDays,

                        'paid_days' =>
                        $paidLeaveDays,

                        'unpaid_days' =>
                        $unpaidLeaveDays,

                        'items' =>
                        $leaveItems->values(),
                    ],

                    /*
                |--------------------------------------------------------------------------
                | Earnings
                |--------------------------------------------------------------------------
                */
                    'earnings' => [
                        'gross_base' =>
                        $record->gross_base,

                        'total_allowances' =>
                        $record->total_allowances,

                        'overtime_hours' =>
                        $record->overtime_hours,

                        'night_diff_pay' =>
                        $record->night_diff_pay,

                        'holiday_pay' =>
                        $record->holiday_pay,

                        'gross_pay' =>
                        $record->gross_pay,
                    ],

                    /*
                |--------------------------------------------------------------------------
                | Deductions
                |--------------------------------------------------------------------------
                */
                    'deductions' => [
                        'total_loan_deductions' =>
                        $record->total_loan_deductions,

                        'total_late_deductions' =>
                        $record->total_late_deductions,

                        'total_deductions' =>
                        $record->total_deductions,

                        'benefits' =>
                        $record->deductions
                            ->whereNotNull('benefit_type_id')
                            ->values(),

                        'loans' =>
                        $record->deductions
                            ->whereNotNull('loan_id')
                            ->values(),
                    ],

                    /*
                |--------------------------------------------------------------------------
                | Allowances
                |--------------------------------------------------------------------------
                */
                    'allowances' => [
                        'total' =>
                        $record->total_allowances,

                        'items' =>
                        $record->allowances,
                    ],

                    /*
                |--------------------------------------------------------------------------
                | Final Payroll
                |--------------------------------------------------------------------------
                */
                    'payroll' => [
                        'gross_pay' =>
                        $record->gross_pay,

                        'total_deductions' =>
                        $record->total_deductions,

                        'net_pay' =>
                        $record->net_pay,
                    ],

                    /*
                |--------------------------------------------------------------------------
                | Remarks
                |--------------------------------------------------------------------------
                */
                    'remarks' =>
                    $record->remarks,
                ];
            });

            /*
        |--------------------------------------------------------------------------
        | Response
        |--------------------------------------------------------------------------
        */
            return response()->json([
                'isSuccess' => true,

                'message' =>
                'Payroll details retrieved successfully.',

                'payroll_period' => [
                    'id' =>
                    $payrollPeriod->id,

                    'period_name' =>
                    $payrollPeriod->period_name,

                    'pay_date' =>
                    $payrollPeriod->pay_date,

                    'cutoff_start_date' =>
                    $payrollPeriod->cutoff_start_date,

                    'cutoff_end_date' =>
                    $payrollPeriod->cutoff_end_date,

                    'status' =>
                    $payrollPeriod->status,
                ],

                'payrolldetails' =>
                $data,

                'pagination' => [
                    'current_page' =>
                    $payrollDetails->currentPage(),

                    'per_page' =>
                    $payrollDetails->perPage(),

                    'total' =>
                    $payrollDetails->total(),

                    'last_page' =>
                    $payrollDetails->lastPage(),
                ],

                'summary' =>
                $summary,
            ]);
        } catch (\Exception $e) {

            Log::error(
                'Error fetching payroll details: ' . $e->getMessage(),
                [
                    'payroll_period_id' => $id,
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return response()->json([
                'isSuccess' => false,

                'message' =>
                'Failed to retrieve payroll details.',

                'error' =>
                $e->getMessage(),
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
                'payrollPeriod',
            ])
                ->where('is_archived', false)
                ->findOrFail($recordId);

            /*
        |--------------------------------------------------------------------------
        | Payroll Period
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

                $holidays = Holiday::with('holidayType')
                    ->whereBetween('holiday_date', [
                        $payrollPeriod->cutoff_start_date,
                        $payrollPeriod->cutoff_end_date,
                    ])
                    ->where('is_archived', 0)
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

                    'holiday_type' => $holiday->holidayType->type_name
                        ?? $holiday->holiday_type
                        ?? 'Holiday',

                    'country' => $holiday->holidayType->country
                        ?? null,

                    'rate' => $holiday->holidayType->rate
                        ?? null,
                ];
            })->values();

            /*
        |--------------------------------------------------------------------------
        | Map Allowances
        |--------------------------------------------------------------------------
        */

            $allowances = $record->allowances->map(function ($allowance) {

                return [
                    'allowance_type' =>
                    $allowance->allowanceType->type_name
                        ?? 'Other Allowance',

                    'allowance_amount' => number_format(
                        $allowance->amount ?? 0,
                        2
                    ),
                ];
            })->values();

            /*
        |--------------------------------------------------------------------------
        | Map Deductions
        |--------------------------------------------------------------------------
        */

            $deductions = $record->deductions->map(function ($deduction) {

                /*
            |--------------------------------------------------------------------------
            | Loan Deduction
            |--------------------------------------------------------------------------
            */

                if ($deduction->loan_id) {

                    return [
                        'deduction_type' => 'Loan Payment',

                        'loan_name' =>
                        $deduction->loan->loanType->type_name
                            ?? 'Loan',

                        'deduction_amount' => number_format(
                            $deduction->amount ?? 0,
                            2
                        ),
                    ];
                }

                /*
            |--------------------------------------------------------------------------
            | Benefit Deduction
            |--------------------------------------------------------------------------
            */

                if ($deduction->benefit_type_id) {

                    return [
                        'deduction_type' =>
                        $deduction->benefitType->benefit_name
                            ?? 'Other Deduction',

                        'deduction_amount' => number_format(
                            $deduction->amount ?? 0,
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
                    'deduction_type' =>
                    $deduction->deduction_name
                        ?? 'Other Deduction',

                    'deduction_amount' => number_format(
                        $deduction->amount ?? 0,
                        2
                    ),
                ];
            })->values();

            /*
        |--------------------------------------------------------------------------
        | Base Pay
        |--------------------------------------------------------------------------
        |
        | days_worked is already calculated by createPayrollPeriod()
        | using:
        |
        | normal worked days
        | + paid leave days
        | + PH holiday worked days
        |
        */

            $basePay =
                (float) $record->daily_rate
                * (float) $record->days_worked;

            /*
        |--------------------------------------------------------------------------
        | Employee Name
        |--------------------------------------------------------------------------
        */

            $employeeName = $record->employee
                ? trim(
                    ($record->employee->first_name ?? '') .
                        ' ' .
                        ($record->employee->last_name ?? '')
                )
                : 'N/A';

            /*
        |--------------------------------------------------------------------------
        | Payroll Period Range
        |--------------------------------------------------------------------------
        */

            $periodRange = 'N/A';

            if ($payrollPeriod) {

                $periodRange =
                    Carbon::parse(
                        $payrollPeriod->cutoff_start_date
                    )->format('F d, Y')
                    . ' - ' .
                    Carbon::parse(
                        $payrollPeriod->cutoff_end_date
                    )->format('F d, Y');
            }

            /*
        |--------------------------------------------------------------------------
        | Return Payslip
        |--------------------------------------------------------------------------
        */

            return response()->json([

                'isSuccess' => true,

                'message' => 'Payslip generated successfully.',

                'payslip' => [

                    /*
                |--------------------------------------------------------------------------
                | Employee
                |--------------------------------------------------------------------------
                */

                    'employee_name' => $employeeName,

                    /*
                |--------------------------------------------------------------------------
                | Payroll Period
                |--------------------------------------------------------------------------
                */

                    'period' =>
                    $payrollPeriod->period_name
                        ?? 'N/A',

                    'period_range' => $periodRange,

                    /*
                |--------------------------------------------------------------------------
                | Basic Payroll Information
                |--------------------------------------------------------------------------
                */

                    'remarks' =>
                    $record->remarks,

                    'daily_rate' => number_format(
                        $record->daily_rate ?? 0,
                        2
                    ),

                    'days_worked' => number_format(
                        $record->days_worked ?? 0,
                        2
                    ),

                    'overtime_hours' => number_format(
                        $record->overtime_hours ?? 0,
                        2
                    ),

                    'absences' =>
                    $record->absences ?? 0,

                    /*
                |--------------------------------------------------------------------------
                | Base Pay
                |--------------------------------------------------------------------------
                */

                    'base_pay' => number_format(
                        $basePay,
                        2
                    ),

                    'gross_base' => number_format(
                        $record->gross_base ?? 0,
                        2
                    ),

                    'gross_pay' => number_format(
                        $record->gross_pay ?? 0,
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

                    'holidays' =>
                    $holidayData,

                    'total_holidays' =>
                    $holidayData->count(),

                    /*
                |--------------------------------------------------------------------------
                | Allowances
                |--------------------------------------------------------------------------
                */

                    'allowances' =>
                    $allowances,

                    'total_allowances' => number_format(
                        $record->total_allowances ?? 0,
                        2
                    ),

                    /*
                |--------------------------------------------------------------------------
                | Deductions
                |--------------------------------------------------------------------------
                */

                    'deductions' =>
                    $deductions,

                    'total_loan_deductions' => number_format(
                        $record->total_loan_deductions ?? 0,
                        2
                    ),

                    'total_late_deductions' => number_format(
                        $record->total_late_deductions ?? 0,
                        2
                    ),

                    'total_deductions' => number_format(
                        $record->total_deductions ?? 0,
                        2
                    ),

                    /*
                |--------------------------------------------------------------------------
                | Net Pay
                |--------------------------------------------------------------------------
                */

                    'net_pay' => number_format(
                        $record->net_pay ?? 0,
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
            ], 200);
        } catch (\Exception $e) {

            Log::error(
                'Error generating payslip: ' .
                    $e->getMessage(),
                [
                    'payroll_record_id' => $recordId,
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return response()->json([
                'isSuccess' => false,

                'message' =>
                'Failed to generate payslip.',

                'error' =>
                $e->getMessage(),
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
}
