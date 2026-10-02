<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AccountCredentialsMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $account,
        public string $temporaryPassword,
        public bool $isNewAccount,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->isNewAccount
                ? 'Your AEA Service Portal account'
                : 'Your AEA Service Portal password was reset',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.account-credentials',
        );
    }
}
