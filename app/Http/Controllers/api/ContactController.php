<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\ContactInquiryMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class ContactController extends Controller
{
    public function submit(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc,dns', 'max:255'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
        ]);

        $submittedAt = now();
        $supportAddress = env('SUPPORT_MAIL_FROM_ADDRESS', 'rentahubsupport@gmail.com');
        $supportName = env('SUPPORT_MAIL_FROM_NAME', 'RentaHub Support');

        Mail::mailer('support_smtp')
            ->to($supportAddress, $supportName)
            ->send(new ContactInquiryMail(
                name: trim($validated['name']),
                email: trim($validated['email']),
                inquiryMessage: trim($validated['message']),
                submittedAt: $submittedAt->toDateTimeString(),
                ipAddress: $request->ip(),
                userAgent: (string) $request->userAgent(),
            ));

        return response()->json([
            'message' => 'Your message has been sent to the RentaHub support team.',
        ], 201);
    }
}
