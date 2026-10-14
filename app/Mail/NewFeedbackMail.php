<?php

namespace App\Mail;

use App\Models\Feedback;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Correo interno (dirigido al staff, no al usuario final) cuando alguien
 * manda un bug/comentario desde la app — ver CLAUDE.md (pedido nuevo, sin
 * spec previa). Sin template de marca: `brand/copies.md` no aplica a texto
 * de herramientas internas, mismo criterio ya usado para el panel admin.
 */
class NewFeedbackMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Feedback $feedback) {}

    public function envelope(): Envelope
    {
        $typeLabel = $this->feedback->type === 'bug' ? 'bug' : 'comentario';
        $sender = $this->feedback->user?->profile?->display_name
            ?? $this->feedback->user?->email
            ?? 'usuario desconocido';

        return new Envelope(
            subject: "Nuevo {$typeLabel} en Prixma — {$sender}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.new-feedback',
            with: [
                'feedback' => $this->feedback,
            ],
        );
    }
}
