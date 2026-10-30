<?php

namespace App\Mail;

use App\Filament\Resources\VerificationRequestResource;
use App\Models\VerificationRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewVerificationRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public VerificationRequest $verificationRequest) {}

    public function envelope(): Envelope
    {
        $applicantName = $this->verificationRequest->profile->display_name
            ?? $this->verificationRequest->profile->user->email
            ?? 'un usuario';

        return new Envelope(
            subject: "Nueva solicitud de verificación de identidad — {$applicantName}",
        );
    }

    public function content(): Content
    {
        $profile = $this->verificationRequest->profile;

        return new Content(
            view: 'emails.new-verification-request',
            with: [
                'applicantName' => $profile->display_name ?? $profile->user->email ?? 'Usuario sin nombre',
                'applicantEmail' => $profile->user->email ?? null,
                'reviewUrl' => VerificationRequestResource::getUrl('view', ['record' => $this->verificationRequest]),
            ]
        );
    }
}
