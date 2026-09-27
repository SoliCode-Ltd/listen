<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewsletterMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $htmlBody,
        public string $textBody,
        public string $subjectLine,
        public string $fromAddress,
        public string $fromName,
        public ?string $replyToAddress = null,
        public ?string $unsubscribeUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromAddress, $this->fromName),
            replyTo: $this->replyToAddress ? [new Address($this->replyToAddress)] : [],
            subject: $this->subjectLine,
            using: [
                function ($message): void {
                    if (! filled($this->unsubscribeUrl)) {
                        return;
                    }

                    $message->getHeaders()->addTextHeader(
                        'List-Unsubscribe',
                        '<'.$this->unsubscribeUrl.'>'
                    );
                },
            ],
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: $this->htmlBody,
            text: 'mail.newsletter-text',
            with: [
                'textBody' => $this->textBody,
            ],
        );
    }
}
