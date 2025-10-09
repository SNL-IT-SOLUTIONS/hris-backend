<?php

namespace App\Http\Controllers;

use App\Models\CompanyInformation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CompanyInformationController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Get the company information (single record).
     */
    public function getCompanyInformation()
    {
        $info = CompanyInformation::first(); // Only one record
        return response()->json($info);
    }

    /**
     * Create or Update the company information (single record).
     */
    public function saveCompanyInformation(Request $request)
    {
        $validated = $request->validate([
            'company_name'        => 'required|string|max:150',
            'industry'            => 'nullable|string|max:100',
            'founded_year'        => 'nullable|integer|min:1800|max:' . date('Y'),
            'website'             => 'nullable|string|max:255',
            'company_mission'     => 'nullable|string',
            'company_vision'      => 'nullable|string',
            'registration_number' => 'nullable|string|max:50',
            'tax_id_ein'          => 'nullable|string|max:50',
            'primary_email'       => 'nullable|email|max:150',
            'phone_number'        => 'nullable|string|max:50',
            'street_address'      => 'nullable|string|max:255',
            'city'                => 'nullable|string|max:100',
            'state_province'      => 'nullable|string|max:100',
            'postal_code'         => 'nullable|string|max:20',
            'country'             => 'nullable|string|max:100',
            'company_logo'        => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048', // Added validation for logo
        ]);

        // Save the uploaded logo (if any)
        if ($request->hasFile('company_logo')) {
            $validated['company_logo'] = $this->saveFileToPublic($request, 'company_logo', 'logo');
        }

        // Create or update the single record
        $info = CompanyInformation::updateOrCreate(
            ['id' => 1], // Always single record
            array_merge($validated, [
                'updated_at' => now(),
            ])
        );

        return response()->json([
            'isSuccess' => true,
            'data' => $info,
            'updated_by' => Auth::check() ? Auth::user()->id : null,
        ]);
    }

    /**
     * Save uploaded file to public/hris_files directory.
     */
    private function saveFileToPublic(Request $request, $field, $prefix)
    {
        if ($request->hasFile($field)) {
            $file = $request->file($field);

            // Directory inside /public
            $directory = public_path('hris_files');
            if (!file_exists($directory)) {
                mkdir($directory, 0755, true);
            }

            // Generate filename: prefix + unique id + original extension
            $filename = $prefix . '_' . uniqid() . '.' . $file->getClientOriginalExtension();

            // Move file to public/hris_files
            $file->move($directory, $filename);

            // Return relative path (to store in DB)
            return 'hris_files/' . $filename;
        }

        return null;
    }
}
