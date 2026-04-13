<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function submit(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc,dns', 'max:255'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
        ]);

        Inquiry::create([
            'name' => trim($validated['name']),
            'email' => trim($validated['email']),
            'message' => trim($validated['message']),
            'status' => 'unread',
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'Your inquiry has been received by the RentaHub admin team.',
        ], 201);
    }
}
