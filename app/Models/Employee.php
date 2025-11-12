<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;

class Employee extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $fillable = [
        'face_id',
        'employee_id',
        'resume',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'email',
        'phone',
        'date_of_birth',
        'place_of_birth',
        'sex',
        'civil_status',
        'height_m',
        'weight_kg',
        'blood_type',
        'citizenship',
        'gsis_no',
        'pagibig_no',
        'philhealth_no',
        'sss_no',
        'tin_no',
        'agency_employee_no',
        'residential_address',
        'residential_zipcode',
        'residential_tel_no',
        'permanent_address',
        'permanent_zipcode',
        'permanent_tel_no',
        'spouse_name',
        'spouse_occupation',
        'spouse_employer',
        'spouse_business_address',
        'spouse_tel_no',
        'father_name',
        'mother_name',
        'parents_address',

        // Education
        'elementary_school_name',
        'elementary_degree_course',
        'elementary_year_graduated',
        'elementary_highest_level',
        'elementary_inclusive_dates',
        'elementary_honors',

        'secondary_school_name',
        'secondary_degree_course',
        'secondary_year_graduated',
        'secondary_highest_level',
        'secondary_inclusive_dates',
        'secondary_honors',

        'vocational_school_name',
        'vocational_degree_course',
        'vocational_year_graduated',
        'vocational_highest_level',
        'vocational_inclusive_dates',
        'vocational_honors',

        'college_school_name',
        'college_degree_course',
        'college_year_graduated',
        'college_highest_level',
        'college_inclusive_dates',
        'college_honors',

        'graduate_school_name',
        'graduate_degree_course',
        'graduate_year_graduated',
        'graduate_highest_level',
        'graduate_inclusive_dates',
        'graduate_honors',

        // Employment
        'department_id',
        'position_id',
        'employment_type_id', // ✅ new FK column
        'manager_id',
        'supervisor_id',
        'base_salary',
        'hire_date',

        // Emergency Contact
        'emergency_contact_name',
        'emergency_contact_number',
        'emergency_contact_relation',

        // Auth-related
        'password',
        'is_active',
        'is_archived',
        'role',
        'is_interviewer',
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'hire_date' => 'date',
        'date_of_birth' => 'date',
    ];

    // 🔹 Relationships
    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function position()
    {
        return $this->belongsTo(PositionType::class, 'position_id');
    }

    public function manager()
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    public function supervisor()
    {
        return $this->belongsTo(Employee::class, 'supervisor_id');
    }

    public function employmentType()
    {
        return $this->belongsTo(EmploymentType::class, 'employment_type_id');
    }

    public function files()
    {
        return $this->hasMany(EmployeeFile::class, 'employee_id');
    }

    // Relationship to employee allowances
    public function employeeAllowances()
    {
        return $this->hasMany(EmployeeAllowance::class, 'employee_id');
    }

    // Relationship to allowances table through pivot
    public function allowances()
    {
        return $this->belongsToMany(AllowanceType::class, 'employee_allowance', 'employee_id', 'allowance_type_id')
            ->withPivot('amount')
            ->withTimestamps();
    }

    // Relationship to employee benefits
    public function employeeBenefits()
    {
        return $this->hasMany(EmployeeBenefit::class, 'employee_id');
    }

    // Relationship to benefits table through pivot
    public function benefits()
    {
        return $this->belongsToMany(BenefitType::class, 'employee_benefit', 'employee_id', 'benefit_type_id')
            ->withPivot('amount')
            ->withTimestamps();
    }


    public function attendances()
    {
        return $this->hasMany(Attendance::class, 'employee_id');
    }

    public function face()
    {
        return $this->hasOne(EmployeeFace::class, 'id', 'face_id');
    }
}
