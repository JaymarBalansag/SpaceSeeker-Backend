<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContactInquiryMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $email,
        public string $inquiryMessage,
        public string $submittedAt,
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                env('SUPPORT_MAIL_FROM_ADDRESS', 'rentahubsupport@gmail.com'),
                env('SUPPORT_MAIL_FROM_NAME', 'RentaHub Support'),
            ),
            subject: 'New Contact Us Inquiry from ' . $this->name,
            replyTo: [
                new Address($this->email, $this->name),
            ],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.contact-inquiry',
        );
    }
}
