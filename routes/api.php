<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CompanyInformationController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\LeaveTypeController;
use App\Http\Controllers\PositionTypeController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\BenefitTypeController;
use App\Http\Controllers\EmploymentTypeController;
use App\Http\Controllers\WorkLocationController;
/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/
//AUTHENTICATION
Route::post('login', [AuthController::class, 'login']);
Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');


//COMPANY INFORMATION
Route::controller(CompanyInformationController::class)->group(function () {
    Route::get('company-information', 'getCompanyInformation');
    Route::post('company-information/save', 'saveCompanyInformation');
});

//EMPLOYEES
Route::controller(EmployeeController::class)->middleware(['auth:sanctum'])->group(function () {
    Route::get('employees', 'getEmployees');
    Route::get('employees/{id}', 'getEmployeeById');
    Route::post('create/employees', 'createEmployee');
    Route::post('update/employees/{id}', 'updateEmployee');
    Route::post('employees/{id}/archive', 'archiveEmployee');
});


//SETUP Work Locations
Route::controller(WorkLocationController::class)->group(function () {
    Route::get('work-locations', 'getAllWorkLocations');
    Route::get('work-locations/{id}', 'getWorkLocationById');
    Route::post('create/work-locations', 'createWorkLocation');
    Route::post('update/work-locations/{id}', 'updateWorkLocation');
    Route::post('work-locations/{id}/archive', 'archiveWorkLocation');
});


//SETUP - EMPLOYMENT TYPES
Route::controller(EmploymentTypeController::class)->group(function () {
    Route::get('employment-types', 'getAllEmploymentTypes');
    Route::get('employment-types/{id}', 'getEmploymentTypeById');
    Route::post('create/employment-types', 'createEmploymentType');
    Route::post('update/employment-types/{id}', 'updateEmploymentType');
    Route::post('employment-types/{id}/archive', 'archiveEmploymentType');
});

//SETUP - BENEFIT TYPES
Route::controller(BenefitTypeController::class)->group(function () {
    Route::get('benefit-types', 'getBenefitTypes');
    Route::get('benefit-types/{id}', 'getBenefitType');
    Route::post('create/benefit-types', 'createBenefitType');
    Route::post('update/benefit-types/{id}', 'updateBenefitType');
    Route::post('benefit-types/{id}/archive', 'archiveBenefitType');
});

//SETUP - ROLES
Route::controller(RoleController::class)->group(function () {
    Route::get('roles', 'getRoles');
    Route::get('roles/{id}', 'getRoleById');
    Route::post('create/roles', 'createRole');
    Route::post('update/roles/{id}', 'updateRole');
    Route::post('/roles/{id}/archive', 'archiveRole');
});

//SETUP - USERS
Route::controller(UserController::class)->group(function () {
    Route::get('users', 'getUsers');
    Route::get('users/{id}', 'getUserById');
    Route::post('create/users', 'createUser');
    Route::post('update/users/{id}', 'updateUser');
    Route::post('users/{id}/archive', 'archiveUser');
});

//SETUP - DEPARTMENTS
Route::controller(DepartmentController::class)->group(function () {
    Route::get('departments', 'getAllDepartments');
    Route::get('departments/{id}', 'getDepartmentById');
    Route::post('create/departments', 'createDepartment');
    Route::post('update/departments/{id}', 'updateDepartment');
    Route::post('departments/{id}/archive', 'archiveDepartment');
});

//SETUP - LEAVE TYPES
Route::controller(LeaveTypeController::class)->group(function () {
    Route::get('leave-types', 'getAllLeaveTypes');
    Route::get('leave-types/{id}', 'getLeaveTypeById');
    Route::post('create/leave-types', 'createLeaveType');
    Route::post('update/leave-types/{id}', 'updateLeaveType');
    Route::post('leave-types/{id}/archive', 'archiveLeaveType');
});

//SETUP - POSITION TYPES
Route::controller(PositionTypeController::class)->group(function () {
    Route::get('position-types', 'getAllPositionTypes');
    Route::get('position-types/{id}', 'getPositionTypeById');
    Route::post('create/position-types', 'createPositionType');
    Route::post('update/position-types/{id}', 'updatePositionType');
    Route::post('position-types/{id}/archive', 'archivePositionType');
});
