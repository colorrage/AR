<?php

namespace CmrManagement\Autoresponder\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AutoresponderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $emailSubject,
        public string $htmlContent
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->emailSubject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'autoresponder::emails.campaign',
            with: [
                'htmlContent' => $this->htmlContent,
                'subject' => $this->emailSubject,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
