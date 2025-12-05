<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BookingRequest extends FormRequest
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
            "property_id" => "required|integer|exists:properties,id",
            "stay_months" => "nullable",
            "occupant_num" => "nullable|integer",
            "lease_duration" => "nullable",
            "custom_months" => "nullable",
            "move_in_date" => "nullable|date",
            "room_preference" => "nullable|string",
            "notes" => "nullable|string",
            "agreement" => "nullable|boolean"


        ];
    }
}
