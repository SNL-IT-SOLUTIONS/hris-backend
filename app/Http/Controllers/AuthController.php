<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\CompanyInformation;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\Employee;

class AuthController extends Controller
{
    /**
     * User Login (email or username)
     */



    public function login(Request $request)
    {
        $credentials = $request->validate([
            'login'    => 'required|string',
            'password' => 'required|string',
        ]);

        $loginInput = $credentials['login'];
        $password = $credentials['password'];

        $user = null;
        $role = null;

        //  Check employees first
        $employee = Employee::where('email', $loginInput)->first();
        if ($employee && Hash::check($password, $employee->password)) {
            $user = $employee;
            $role = 'employee';
        }

        //  If not found, check users
        if (!$user) {
            $fieldType = filter_var($loginInput, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
            $userModel = User::where($fieldType, $loginInput)->first();
            if ($userModel && Hash::check($password, $userModel->password)) {
                $user = $userModel;
                $role = 'user';
            }
        }

        // Invalid login
        if (!$user) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Invalid login credentials.',
            ], 401);
        }

        // Optional: check if account is active
        if (isset($user->is_active) && !$user->is_active) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Your account is inactive.',
            ], 403);
        }

        // Generate token
        $token = $user->createToken('auth_token')->plainTextToken;

        // Fetch company info (assuming single record)
        $company = CompanyInformation::first();

        // Response
        return response()->json([
            'isSuccess' => true,
            'message' => 'Login successful.',
            'role' => $role,
            'user' => $role === 'employee' ? [
                'id' => $user->id,
                'employee_id' => $user->employee_id,
                'face_id' => $user->face_id,
                'profile_picture' => $user->profile_picture
                    ? asset($user->profile_picture)
                    : null,

                // PERSONAL INFORMATION
                'first_name' => $user->first_name,
                'middle_name' => $user->middle_name,
                'last_name' => $user->last_name,
                'suffix' => $user->suffix,
                'email' => $user->email,
                'phone' => $user->phone,
                'sex' => $user->sex,
                'salary_mode' => $user->salary_mode,
                'date_of_birth' => $user->date_of_birth,
                'place_of_birth' => $user->place_of_birth,
                'civil_status' => $user->civil_status,
                'citizenship' => $user->citizenship,

                // PHYSICAL INFO
                'height_m' => $user->height_m,
                'weight_kg' => $user->weight_kg,
                'blood_type' => $user->blood_type,

                // GOVERNMENT IDS
                'gsis_no' => $user->gsis_no,
                'pagibig_no' => $user->pagibig_no,
                'philhealth_no' => $user->philhealth_no,
                'sss_no' => $user->sss_no,
                'tin_no' => $user->tin_no,

                // ADDRESSES
                'residential_address' => $user->residential_address,
                'residential_zipcode' => $user->residential_zipcode,
                'residential_tel_no' => $user->residential_tel_no,
                'permanent_address' => $user->permanent_address,
                'permanent_zipcode' => $user->permanent_zipcode,
                'permanent_tel_no' => $user->permanent_tel_no,

                // FAMILY INFO
                'spouse_name' => $user->spouse_name,
                'spouse_occupation' => $user->spouse_occupation,
                'spouse_employer' => $user->spouse_employer,
                'spouse_business_address' => $user->spouse_business_address,
                'spouse_tel_no' => $user->spouse_tel_no,
                'father_name' => $user->father_name,
                'mother_name' => $user->mother_name,
                'parents_address' => $user->parents_address,

                // EDUCATION
                'elementary_school_name' => $user->elementary_school_name,
                'elementary_degree_course' => $user->elementary_degree_course,
                'elementary_year_graduated' => $user->elementary_year_graduated,
                'elementary_highest_level' => $user->elementary_highest_level,
                'elementary_inclusive_dates' => $user->elementary_inclusive_dates,
                'elementary_honors' => $user->elementary_honors,

                'secondary_school_name' => $user->secondary_school_name,
                'secondary_degree_course' => $user->secondary_degree_course,
                'secondary_year_graduated' => $user->secondary_year_graduated,
                'secondary_highest_level' => $user->secondary_highest_level,
                'secondary_inclusive_dates' => $user->secondary_inclusive_dates,
                'secondary_honors' => $user->secondary_honors,

                'vocational_school_name' => $user->vocational_school_name,
                'vocational_degree_course' => $user->vocational_degree_course,
                'vocational_year_graduated' => $user->vocational_year_graduated,
                'vocational_highest_level' => $user->vocational_highest_level,
                'vocational_inclusive_dates' => $user->vocational_inclusive_dates,
                'vocational_honors' => $user->vocational_honors,

                'college_school_name' => $user->college_school_name,
                'college_degree_course' => $user->college_degree_course,
                'college_year_graduated' => $user->college_year_graduated,
                'college_highest_level' => $user->college_highest_level,
                'college_inclusive_dates' => $user->college_inclusive_dates,
                'college_honors' => $user->college_honors,

                'graduate_school_name' => $user->graduate_school_name,
                'graduate_degree_course' => $user->graduate_degree_course,
                'graduate_year_graduated' => $user->graduate_year_graduated,
                'graduate_highest_level' => $user->graduate_highest_level,
                'graduate_inclusive_dates' => $user->graduate_inclusive_dates,
                'graduate_honors' => $user->graduate_honors,

                // EMERGENCY CONTACT
                'emergency_contact_name' => $user->emergency_contact_name,
                'emergency_contact_number' => $user->emergency_contact_number,
                'emergency_contact_relation' => $user->emergency_contact_relation,

                // FILE
                'resume' => $user->resume
                    ? asset('storage/' . $user->resume)
                    : null,
            ] : [
                // NON-EMPLOYEE USER
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'username' => $user->username,
            ],

            'company_information' => $company ? [
                'id' => $company->id,
                'company_name' => $company->company_name,
                'company_logo' => $company->company_logo,
                'industry' => $company->industry,
                'founded_year' => $company->founded_year,
                'website' => $company->website,
                'company_mission' => $company->company_mission,
                'company_vision' => $company->company_vision,
                'registration_number' => $company->registration_number,
                'tax_id_ein' => $company->tax_id_ein,
                'primary_email' => $company->primary_email,
                'phone_number' => $company->phone_number,
                'street_address' => $company->street_address,
                'city' => $company->city,
                'state_province' => $company->state_province,
                'postal_code' => $company->postal_code,
                'country' => $company->country,
            ] : null,
            'token' => $token,
        ]);
    }

    /**
     * User Logout
     */
    public function logout(Request $request)
    {
        $user = $request->user();

        if ($user) {
            // Revoke current token
            $user->currentAccessToken()->delete();

            return response()->json([
                'isSuccess' => true,
                'message'   => 'Logged out successfully.',
            ]);
        }

        return response()->json([
            'isSuccess' => false,
            'message'   => 'User not authenticated.',
        ], 401);
    }

    /**
     * Get Authenticated User Info
     */
    public function me(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'isSuccess' => false,
                'message'   => 'No authenticated user found.',
            ], 401);
        }

        return response()->json([
            'isSuccess' => true,
            'user'      => $user,
        ]);
    }

    public function getMyProfile(Request $request)
    {
        $employee = auth()->user(); // Employee model via Sanctum

        if ($employee->role !== 'employee') {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Unauthorized.',
            ], 403);
        }

        return response()->json([
            'isSuccess' => true,
            'user' => [
                'id' => $employee->id,
                'employee_id' => $employee->employee_id,
                'face_id' => $employee->face_id,

                // PERSONAL INFORMATION
                'first_name' => $employee->first_name,
                'middle_name' => $employee->middle_name,
                'last_name' => $employee->last_name,
                'suffix' => $employee->suffix,
                'email' => $employee->email,
                'phone' => $employee->phone,
                'sex' => $employee->sex,
                'salary_mode' => $employee->salary_mode,
                'date_of_birth' => $employee->date_of_birth,
                'place_of_birth' => $employee->place_of_birth,
                'civil_status' => $employee->civil_status,
                'citizenship' => $employee->citizenship,

                // PHYSICAL INFO
                'height_m' => $employee->height_m,
                'weight_kg' => $employee->weight_kg,
                'blood_type' => $employee->blood_type,

                // GOVERNMENT IDS
                'gsis_no' => $employee->gsis_no,
                'pagibig_no' => $employee->pagibig_no,
                'philhealth_no' => $employee->philhealth_no,
                'sss_no' => $employee->sss_no,
                'tin_no' => $employee->tin_no,

                // ADDRESSES
                'residential_address' => $employee->residential_address,
                'residential_zipcode' => $employee->residential_zipcode,
                'residential_tel_no' => $employee->residential_tel_no,
                'permanent_address' => $employee->permanent_address,
                'permanent_zipcode' => $employee->permanent_zipcode,
                'permanent_tel_no' => $employee->permanent_tel_no,

                // FAMILY INFO
                'spouse_name' => $employee->spouse_name,
                'spouse_occupation' => $employee->spouse_occupation,
                'spouse_employer' => $employee->spouse_employer,
                'spouse_business_address' => $employee->spouse_business_address,
                'spouse_tel_no' => $employee->spouse_tel_no,
                'father_name' => $employee->father_name,
                'mother_name' => $employee->mother_name,
                'parents_address' => $employee->parents_address,

                // EDUCATION
                'elementary_school_name' => $employee->elementary_school_name,
                'elementary_degree_course' => $employee->elementary_degree_course,
                'elementary_year_graduated' => $employee->elementary_year_graduated,
                'elementary_highest_level' => $employee->elementary_highest_level,
                'elementary_inclusive_dates' => $employee->elementary_inclusive_dates,
                'elementary_honors' => $employee->elementary_honors,

                'secondary_school_name' => $employee->secondary_school_name,
                'secondary_degree_course' => $employee->secondary_degree_course,
                'secondary_year_graduated' => $employee->secondary_year_graduated,
                'secondary_highest_level' => $employee->secondary_highest_level,
                'secondary_inclusive_dates' => $employee->secondary_inclusive_dates,
                'secondary_honors' => $employee->secondary_honors,

                'vocational_school_name' => $employee->vocational_school_name,
                'vocational_degree_course' => $employee->vocational_degree_course,
                'vocational_year_graduated' => $employee->vocational_year_graduated,
                'vocational_highest_level' => $employee->vocational_highest_level,
                'vocational_inclusive_dates' => $employee->vocational_inclusive_dates,
                'vocational_honors' => $employee->vocational_honors,

                'college_school_name' => $employee->college_school_name,
                'college_degree_course' => $employee->college_degree_course,
                'college_year_graduated' => $employee->college_year_graduated,
                'college_highest_level' => $employee->college_highest_level,
                'college_inclusive_dates' => $employee->college_inclusive_dates,
                'college_honors' => $employee->college_honors,

                'graduate_school_name' => $employee->graduate_school_name,
                'graduate_degree_course' => $employee->graduate_degree_course,
                'graduate_year_graduated' => $employee->graduate_year_graduated,
                'graduate_highest_level' => $employee->graduate_highest_level,
                'graduate_inclusive_dates' => $employee->graduate_inclusive_dates,
                'graduate_honors' => $employee->graduate_honors,

                // EMERGENCY CONTACT
                'emergency_contact_name' => $employee->emergency_contact_name,
                'emergency_contact_number' => $employee->emergency_contact_number,
                'emergency_contact_relation' => $employee->emergency_contact_relation,

                // FILES
                'resume' => $employee->resume
                    ? asset($employee->resume)
                    : null,

                'profile_picture' => $employee->profile_picture
                    ? asset($employee->profile_picture)
                    : null,
            ],
        ]);
    }

    public function updateProfile(Request $request)
    {
        $employee = auth()->user();

        if ($employee->role !== 'employee') {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Unauthorized.',
            ], 403);
        }

        $validated = $request->validate([
            // File
            'resume' => 'nullable|file|mimes:pdf,doc,docx,jpeg,png,xlsx|max:2048',
            'profile_picture' => 'nullable|file|mimes:jpeg,png|max:2048',
            // Basic Info
            'first_name'   => 'nullable|string|max:100',
            'middle_name'  => 'nullable|string|max:100',
            'last_name'    => 'nullable|string|max:100',
            'suffix'       => 'nullable|string|max:10',
            'email'        => 'nullable|email|unique:employees,email,' . $employee->id,
            'phone'        => 'nullable|string|max:50',
            'date_of_birth' => 'nullable|date',
            'place_of_birth' => 'nullable|string|max:255',
            'sex'          => 'nullable|string|max:10',
            'salary_mode'  => 'nullable|string|max:255',
            'civil_status' => 'nullable|string|max:50',
            'height_m'     => 'nullable|numeric',
            'weight_kg'    => 'nullable|numeric',
            'blood_type'   => 'nullable|string|max:5',
            'citizenship'  => 'nullable|string|max:100',

            // Government IDs
            'gsis_no'       => 'nullable|string|max:50',
            'pagibig_no'    => 'nullable|string|max:50',
            'philhealth_no' => 'nullable|string|max:50',
            'sss_no'        => 'nullable|string|max:50',
            'tin_no'        => 'nullable|string|max:50',

            // Address
            'residential_address' => 'nullable|string|max:255',
            'residential_zipcode' => 'nullable|string|max:10',
            'residential_tel_no'  => 'nullable|string|max:50',
            'permanent_address'   => 'nullable|string|max:255',
            'permanent_zipcode'   => 'nullable|string|max:10',
            'permanent_tel_no'    => 'nullable|string|max:50',

            // Family
            'spouse_name' => 'nullable|string|max:255',
            'spouse_occupation' => 'nullable|string|max:255',
            'spouse_employer' => 'nullable|string|max:255',
            'spouse_business_address' => 'nullable|string|max:255',
            'spouse_tel_no' => 'nullable|string|max:50',
            'father_name' => 'nullable|string|max:255',
            'mother_name' => 'nullable|string|max:255',
            'parents_address' => 'nullable|string|max:255',

            // Education
            'elementary_school_name' => 'nullable|string|max:255',
            'elementary_degree_course' => 'nullable|string|max:255',
            'elementary_year_graduated' => 'nullable|string|max:10',
            'elementary_highest_level' => 'nullable|string|max:100',
            'elementary_inclusive_dates' => 'nullable|string|max:50',
            'elementary_honors' => 'nullable|string|max:255',

            'secondary_school_name' => 'nullable|string|max:255',
            'secondary_degree_course' => 'nullable|string|max:255',
            'secondary_year_graduated' => 'nullable|string|max:10',
            'secondary_highest_level' => 'nullable|string|max:100',
            'secondary_inclusive_dates' => 'nullable|string|max:50',
            'secondary_honors' => 'nullable|string|max:255',

            'vocational_school_name' => 'nullable|string|max:255',
            'vocational_degree_course' => 'nullable|string|max:255',
            'vocational_year_graduated' => 'nullable|string|max:10',
            'vocational_highest_level' => 'nullable|string|max:100',
            'vocational_inclusive_dates' => 'nullable|string|max:50',
            'vocational_honors' => 'nullable|string|max:255',

            'college_school_name' => 'nullable|string|max:255',
            'college_degree_course' => 'nullable|string|max:255',
            'college_year_graduated' => 'nullable|string|max:10',
            'college_highest_level' => 'nullable|string|max:100',
            'college_inclusive_dates' => 'nullable|string|max:50',
            'college_honors' => 'nullable|string|max:255',

            'graduate_school_name' => 'nullable|string|max:255',
            'graduate_degree_course' => 'nullable|string|max:255',
            'graduate_year_graduated' => 'nullable|string|max:10',
            'graduate_highest_level' => 'nullable|string|max:100',
            'graduate_inclusive_dates' => 'nullable|string|max:50',
            'graduate_honors' => 'nullable|string|max:255',

            // Emergency
            'emergency_contact_name' => 'nullable|string|max:255',
            'emergency_contact_number' => 'nullable|string|max:50',
            'emergency_contact_relation' => 'nullable|string|max:100',
        ]);

        //  Resume upload (using saveFileToPublic)
        if ($request->hasFile('resume', 'profile_picture')) {

            // Delete old resume (manual, since it's in public/)
            if ($employee->resume) {
                $oldPath = public_path($employee->resume);
                if (file_exists($oldPath)) {
                    unlink($oldPath);
                }
            }

            if ($employee->profile_picture) {
                $oldProfilePicturePath = public_path($employee->profile_picture);
                if (file_exists($oldProfilePicturePath)) {
                    unlink($oldProfilePicturePath);
                }
            }

            $validated['resume'] = $this->saveFileToPublic(
                $request->file('resume'),
                'employee_resumes'
            );

            $validated['profile_picture'] = $this->saveFileToPublic(
                $request->file('profile_picture'),
                'employee_profile_pictures'
            );
        }

        // Update employee
        $employee->update($validated);

        return response()->json([
            'isSuccess' => true,
            'message' => 'Profile updated successfully.',
            'user' => [
                'id' => $employee->id,
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'email' => $employee->email,
                'resume' => $employee->resume
                    ? asset($employee->resume)
                    : null,
            ],
        ]);
    }


    public function updateProfilePicture(Request $request)
    {
        $employee = auth()->user(); // Employee model via Sanctum

        if ($employee->role !== 'employee') {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Unauthorized.',
            ], 403);
        }

        $validated = $request->validate([
            'profile_picture' => 'required|file|mimes:jpeg,png|max:2048',
        ]);

        // Delete old profile picture
        if ($employee->profile_picture) {
            $oldPath = public_path($employee->profile_picture);
            if (file_exists($oldPath)) {
                unlink($oldPath);
            }
        }

        // Save new profile picture
        $validated['profile_picture'] = $this->saveFileToPublic(
            $request->file('profile_picture'),
            'employee_profile_pictures'
        );

        // Update employee
        $employee->update($validated);

        return response()->json([
            'isSuccess' => true,
            'message' => 'Profile picture updated successfully.',
            'user' => [
                'id' => $employee->id,
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'email' => $employee->email,
                'profile_picture' => $employee->profile_picture
                    ? asset($employee->profile_picture)
                    : null,
            ],
        ]);
    }

    public function changePassword(Request $request)
    {
        $employee = auth()->user(); // Employee model via Sanctum

        if ($employee->role !== 'employee') {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Unauthorized.',
            ], 403);
        }

        $validated = $request->validate([
            'current_password' => 'required|string',
            'new_password'     => 'required|string|min:6|confirmed',
        ]);

        // Check current password
        if (!Hash::check($validated['current_password'], $employee->password)) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Current password is incorrect.',
            ], 400);
        }

        // Update password
        $employee->password = Hash::make($validated['new_password']);
        $employee->save();

        return response()->json([
            'isSuccess' => true,
            'message' => 'Password changed successfully.',
        ]);
    }


    private function saveFileToPublic($fileInput, $prefix)
    {
        $directory = public_path('hris_files');
        if (!file_exists($directory)) {
            mkdir($directory, 0755, true);
        }

        $saveSingleFile = function ($file) use ($directory, $prefix) {
            $filename = $prefix . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $file->move($directory, $filename);
            return 'hris_files/' . $filename;
        };

        //  Case 1: Multiple files
        if (is_array($fileInput)) {
            $paths = [];
            foreach ($fileInput as $file) {
                $paths[] = $saveSingleFile($file);
            }
            return $paths; // Return array of paths
        }

        // Case 2: Single file
        if ($fileInput instanceof \Illuminate\Http\UploadedFile) {
            return $saveSingleFile($fileInput);
        }

        return null;
    }
}
