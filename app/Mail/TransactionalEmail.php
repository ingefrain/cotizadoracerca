<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TransactionalEmail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /** @param array<string, string> $contentData */
    public function __construct(public array $contentData) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->contentData['subject']);
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.transactional',
            with: [
                'contentData' => $this->contentData,
                'branding' => config('notifications.branding'),
            ],
        );
    }
}
