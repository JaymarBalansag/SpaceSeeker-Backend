<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TenantsRequest extends FormRequest
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
            "booking_id" => "required|integer|exists:bookings,id",
            "property_id" => "required|integer|exists:properties,id",
            "user_id" => "required|integer|exists:users,id",
            "status" => "required|string|in:pending,approved,rejected",
            "move_in_data" => "nullable|data",
            "stay_duration" => "nullable|integer",
            "occupants_num" => "nullable|integer",
            "lease_duration" => "nullable|integer",
            "room_preference" => "nullable|string",
            "notes" => "nullable|string",
            "agreement" => "required|boolean",
            
        ];
    }
}
