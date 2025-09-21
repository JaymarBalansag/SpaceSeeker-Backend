<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProfileCompletionRequest extends FormRequest
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
            'phone_number' => 'nullable|string|max:20',
            'streets'      => 'nullable|string|max:255',
            'region_id'    => 'nullable|integer',
            'province_id'  => 'nullable|integer',
            'muncity_id'   => 'nullable|integer',
            'barangay_id'  => 'nullable|integer',
            'latitude'     => 'nullable|numeric',
            'longitude'    => 'nullable|numeric',
            'user_img'     => 'nullable|image|max:2048',
        ];
    }
}
