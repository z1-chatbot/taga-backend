<?php

namespace App\Mail\Concerns;

use Illuminate\Contracts\Mail\Factory as MailFactory;
use LogicException;

/**
 * Sends a message from the mailbox its own class declares.
 *
 * Laravel throws away a mailable's choice of mailer the moment it is
 * dispatched. Illuminate\Mail\Mailer::sendMailable() does
 *
 *     $mailable->mailer($this->name)->send($this)
 *
 * writing the name of whichever mailer picked the message up over $mailer
 * before anything else happens. Mail::to(), Mail::send() and Mail::queue()
 * all run through the default mailer, so every message dispatched that way
 * went out as the default identity regardless of what it asked for.
 *
 * Here that is not cosmetic. Each Hostinger mailbox is a separate SMTP
 * account and may only send as its own address, so the wrong mailer means
 * the wrong credentials AND the wrong From — a pairing the server refuses:
 *
 *     553 5.7.1 <support@taga.ng>: Sender address rejected:
 *                not owned by user hello@z1stores.com
 *
 * $mailbox is deliberately a different property from $mailer, because $mailer
 * is the one the framework writes to. Restoring it here — at the last moment,
 * after the clobbering has already happened — is what makes the declaration
 * mean something.
 *
 * Passing the mail *manager* to parent::send() is the other half: Mailable
 * only consults $mailer when the thing handed in can resolve mailers by name.
 * A single Mailer instance cannot, so it would simply send as itself.
 *
 * Queued mailables need no separate handling: SendQueuedMailable::handle()
 * calls send() as well, so the restore happens again on the worker even
 * though the property was serialised in its clobbered state.
 */
trait SendsFromMailbox
{
    /*
     * The using class must declare which mailbox it goes out from:
     *
     *     protected string $mailbox = 'noreply';   // or shop, or support
     *
     * It cannot be declared here as well — PHP rejects a trait and a class
     * that both define a property when the defaults differ, and the whole
     * point is that the default differs per message.
     */

    /**
     * @param  \Illuminate\Contracts\Mail\Factory|\Illuminate\Contracts\Mail\Mailer  $mailer
     */
    public function send($mailer)
    {
        if (! isset($this->mailbox)) {
            // Loud, rather than quietly borrowing the default identity — which
            // the mail server would reject anyway, later and less legibly.
            throw new LogicException(static::class.' declares no $mailbox. Set it to noreply, shop or support.');
        }

        $this->mailer = $this->mailbox;

        return parent::send(app(MailFactory::class));
    }
}
