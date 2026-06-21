<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

class VerifyEmailMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $verificationUrl;

    public function __construct(public User $user)
    {
        $this->verificationUrl = URL::temporarySignedRoute(
            'email.verify',
            now()->addDays(7),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Ya casi estás adentro — verifica tu cuenta de Prixma',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.verify-email',
        );
    }
}
