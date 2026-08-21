<?php

namespace App\Mail;

use App\Mail\Concerns\SendsFromMailbox;
use App\Models\AgentInvitation;
use App\Models\LogisticsCompany;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AgentInvitationEmail extends Mailable
{
    use Queueable, SerializesModels, SendsFromMailbox;

    /**
     * Sent from the noreply mailbox — identity: account creation and access, no reply expected.
     *
     * Each mailbox is its own SMTP account and may only send as its own
     * address, so this picks the credentials as well as the From line.
     */
    protected string $mailbox = 'noreply';

    public $agentName;
    public $agentEmail;
    public $defaultPassword;
    public $companyName;
    public $loginUrl;

    /**
     * Create a new message instance.
     */
    public function __construct(string $agentName, string $agentEmail, string $defaultPassword, string $companyName, string $loginUrl)
    {
        $this->agentName = $agentName;
        $this->agentEmail = $agentEmail;
        $this->defaultPassword = $defaultPassword;
        $this->companyName = $companyName;
        $this->loginUrl = $loginUrl;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'You\'ve Been Invited to Join ' . $this->companyName . ' as a Delivery Agent',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.agent-invitation',
            text: 'emails.agent-invitation-text',
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
