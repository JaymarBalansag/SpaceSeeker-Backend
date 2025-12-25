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
            'region_name'    => 'nullable|string',
            'state_name'  => 'nullable|string',
            'town_name'   => 'nullable|string',
            'village_name'  => 'nullable|string',
            'latitude'     => 'nullable|numeric',
            'longitude'    => 'nullable|numeric',
            'user_img'     => 'nullable|image',
        ];
    }
}
