<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AssessmentStatementMail extends Mailable
{
    /** @param array{headline:string,due:string,received:string,as_at:string} $summary */
    public function __construct(
        public string $societyName,
        public string $circuitName,
        public string $monthLabel,
        public string $subjectLine,
        public array $summary,
        public string $pdf,
        public string $filename,
        public ?string $replyToEmail,
        public string $senderName,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine,
            replyTo: $this->replyToEmail ? [new Address($this->replyToEmail, $this->senderName)] : [],
        );
    }

    public function content(): Content
    {
        return new Content(text: 'emails.assessment-statement');
    }

    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->pdf, $this->filename)->withMime('application/pdf')];
    }
}