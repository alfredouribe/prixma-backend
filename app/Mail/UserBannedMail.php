<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UserBannedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user, public string $reasonLabel) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Tu cuenta de Prixma ha sido deshabilitada',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.user-banned',
            with: [
                'reasonLabel' => $this->reasonLabel,
            ]
        );
    }
}
