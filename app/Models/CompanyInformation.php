<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanyInformation extends Model
{
    use HasFactory;

    // Table name (optional, but good to specify)
    protected $table = 'company_information';

    // Allow mass assignment for these fields
    protected $fillable = [
        'company_name',
        'company_logo',
        'industry',
        'founded_year',
        'website',
        'company_mission',
        'company_vision',
        'registration_number',
        'tax_id_ein',
        'primary_email',
        'phone_number',
        'street_address',
        'city',
        'state_province',
        'postal_code',
        'country',
    ];

    // Use timestamps (created_at, updated_at)
    public $timestamps = true;
}
