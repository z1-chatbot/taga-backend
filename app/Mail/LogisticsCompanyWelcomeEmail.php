<?php

namespace App\Mail;

use App\Mail\Concerns\SendsFromMailbox;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LogisticsCompanyWelcomeEmail extends Mailable
{
    use Queueable, SerializesModels, SendsFromMailbox;

    /**
     * Sent from the noreply mailbox — identity: account creation and access, no reply expected.
     *
     * Each mailbox is its own SMTP account and may only send as its own
     * address, so this picks the credentials as well as the From line.
     */
    protected string $mailbox = 'noreply';

    public $companyName;
    public $adminEmail;
    public $defaultPassword;
    public $loginUrl;

    /**
     * Create a new message instance.
     */
    public function __construct(string $companyName, string $adminEmail, string $defaultPassword, string $loginUrl)
    {
        $this->companyName = $companyName;
        $this->adminEmail = $adminEmail;
        $this->defaultPassword = $defaultPassword;
        $this->loginUrl = $loginUrl;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to Taga — Your Logistics Company Account is Ready',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.logistics-company-welcome',
            text: 'emails.logistics-company-welcome-text',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
