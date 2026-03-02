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
            'latitude'     => 'nullable|numeric|between:-90,90',
            'longitude'    => 'nullable|numeric|between:-180,180',
            'user_img'     => 'nullable|image',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $lat = $this->input('latitude');
            $lng = $this->input('longitude');

            if ($lat === null || $lng === null) {
                return;
            }

            $latFloat = (float) $lat;
            $lngFloat = (float) $lng;

            if (abs($latFloat) < 0.000001 && abs($lngFloat) < 0.000001) {
                $validator->errors()->add('latitude', 'Latitude and longitude cannot both be zero.');
            }
        });
    }
}
