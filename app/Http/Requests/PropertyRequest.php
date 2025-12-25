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
            'thumbnail' => 'nullable|image',
            'description' => 'nullable|string',

            // Property Prices
            'price' => 'required|numeric',
            'utilities_included' => 'nullable|boolean',
            'agreement_type' => 'required|in:rental,lease',
            'advance_payment_months' => 'nullable|integer|min:0',
            'deposit_required' => 'nullable|numeric|min:0',
            'payment_frequency' => 'required|in:daily,weekly,monthly,yearly',
            'lease_term_months' => 'nullable|integer',
            'renewal_option' => 'nullable|string',
            'notice_period' => 'nullable|integer|min:0',
            'has_curfew' => 'nullable|boolean',
            'curfew_time' => 'nullable|date_format:H:i',

            // Property Features
            'property_type_id' => 'required|exists:property_types,id',
            'furnishing' => 'nullable|in:unfurnished,semi-furnished,fully-furnished',
            'parking' => 'nullable|boolean',
            'bedrooms' => 'nullable|integer|min:0',
            'bathrooms' => 'nullable|integer|min:0',
            'bed_space' => 'nullable|integer|min:0',
            'floor_area' => 'nullable|numeric|min:0',
            'lot_area' => 'nullable|numeric|min:0',
            'max_size' => 'nullable|integer|min:0',

            // Location
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'region_name' => 'nullable|string',
            'state_name' => 'nullable|string',
            'town_name' => 'nullable|string',
            'village_name' => 'nullable|string',

            // Rules
            'rules' => 'nullable|string', 
        ];
    }
}
