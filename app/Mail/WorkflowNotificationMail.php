<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WorkflowNotificationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * One shared template for every workflow event (request assigned, job
     * scheduled, quotation approved, invoice issued, and so on), rather than
     * a separate Mailable per event. Each call site supplies its own
     * subject and plain-English lines.
     *
     * @param  array<int, string>  $lines
     */
    public function __construct(
        public string $greetingName,
        public string $subjectLine,
        public array $lines,
        public ?string $ctaUrl = null,
        public ?string $ctaLabel = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.workflow-notification',
        );
    }
}
