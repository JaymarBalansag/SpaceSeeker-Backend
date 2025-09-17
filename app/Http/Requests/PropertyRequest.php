<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PropertyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'thumbnail' => 'nullable|image|max:1024',
            'rules' => 'nullable|string',   
            // Property Prices
            'price' => 'required|numeric',
            'parking' => 'nullable',
            'utilities_included' => 'nullable',
            'advance_payment' => 'nullable|numeric',
            'deposit_required' => 'nullable|numeric',
            'payment_frequency' => 'nullable|string',

            'lease_term_moneths' => 'nullable|numeric',
            'renewal_option' => 'nullable|string',
            'notice_period' => 'nullable|numeric',
            'has_curfew' => 'nullable',
            'curfew_time' => 'nullable',
            // Property Features
            'agreement_type' => 'required|string',
            'property_type_id' => 'required|exists:property_types,id',
            'furnishing' => 'nullable|string',
            'bedrooms' => 'nullable|numeric',
            'bed_space' => 'nullable|numeric',
            'floor_area' => 'nullable|numeric',
            'lot_area' => 'nullable|numeric',
            'max_size' => 'nullable|numeric',
            // Location
            'region_id' => 'nullable',
            'province_id' => 'nullable',
            'muncity_id' => 'nullable',
            'barangay_id' => 'nullable',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',

            'images.*' => 'nullable|image|max:2048',
            'property_facilities.*' => 'exists:facilities,id',
            'property_amenities.*' => 'exists:amenities,id',
        ];
    }
}
