<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserLocationRequest extends FormRequest
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
            'streets'      => 'nullable|string|max:255',
            'region_name'    => 'nullable|string',
            'state_name'  => 'nullable|string',
            'town_name'   => 'nullable|string',
            'village_name'  => 'nullable|string',
            'latitude'     => 'nullable|numeric',
            'longitude'    => 'nullable|numeric',
        ];
    }
}
