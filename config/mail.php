<?php

/*
 * The three Taga mailboxes are real SMTP accounts in production, but they must
 * not be when the default driver is not SMTP. Tests run with MAIL_MAILER=array
 * and local setups often use "log"; without this, routing a message to its
 * mailbox would reach for a live connection to Hostinger during a test run.
 *
 * So the mailboxes follow the default driver whenever it is not smtp, and are
 * real SMTP accounts whenever it is.
 */
$mailboxTransport = in_array(env('MAIL_MAILER', 'smtp'), ['array', 'log'], true)
    ? env('MAIL_MAILER')
    : 'smtp';

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send all email
    | messages unless another mailer is explicitly specified when sending
    | the message. All additional mailers can be configured within the
    | "mailers" array. Examples of each type of mailer are provided.
    |
    */

    'default' => env('MAIL_MAILER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers that can be used
    | when delivering an email. You may specify which one you're using for
    | your mailers below. You may also add additional mailers if needed.
    |
    | Supported: "smtp", "sendmail", "mailgun", "ses", "ses-v2",
    |            "postmark", "resend", "log", "array",
    |            "failover", "roundrobin"
    |
    */

    'mailers' => [

        /*
        |----------------------------------------------------------------------
        | The three Taga mailboxes
        |----------------------------------------------------------------------
        |
        | Each Hostinger mailbox is a separate SMTP account, and an account may
        | only send as its own address — sending as anything else is refused
        | with "553 Sender address rejected". So a different From address means
        | a different mailer, not just a different `from` line.
        |
        | Note each `from.address` defaults to that mailer's own username. That
        | is deliberate: it makes the mismatch that rejected the first 53 emails
        | structurally impossible to reintroduce by editing one variable.
        |
        | Every credential falls back to the base MAIL_* values, so a single
        | configured mailbox keeps the whole platform sending while the others
        | are being set up.
        |
        |   noreply  identity: sign-up, verification, password reset
        |   shop     commerce: orders, cart, delivery, payouts, store/courier
        |   support  anything a person is expected to reply to
        |
        | Replies to noreply and shop are pointed at support, so a customer who
        | hits reply reaches a human instead of a black hole.
        */

        'noreply' => [
            'transport' => $mailboxTransport,
            'host' => env('MAIL_NOREPLY_HOST', env('MAIL_HOST', '127.0.0.1')),
            'port' => env('MAIL_NOREPLY_PORT', env('MAIL_PORT', 2525)),
            'encryption' => env('MAIL_NOREPLY_ENCRYPTION', env('MAIL_ENCRYPTION', 'tls')),
            'username' => env('MAIL_NOREPLY_USERNAME', env('MAIL_USERNAME')),
            'password' => env('MAIL_NOREPLY_PASSWORD', env('MAIL_PASSWORD')),
            'timeout' => null,
            'from' => [
                'address' => env('MAIL_NOREPLY_USERNAME', env('MAIL_USERNAME', env('MAIL_FROM_ADDRESS', 'hello@example.com'))),
                'name' => env('MAIL_FROM_NAME', 'Taga'),
            ],
            'reply_to' => [
                'address' => env('MAIL_SUPPORT_USERNAME', env('MAIL_FROM_ADDRESS', 'hello@example.com')),
                'name' => env('MAIL_FROM_NAME', 'Taga'),
            ],
        ],

        'shop' => [
            'transport' => $mailboxTransport,
            'host' => env('MAIL_SHOP_HOST', env('MAIL_HOST', '127.0.0.1')),
            'port' => env('MAIL_SHOP_PORT', env('MAIL_PORT', 2525)),
            'encryption' => env('MAIL_SHOP_ENCRYPTION', env('MAIL_ENCRYPTION', 'tls')),
            'username' => env('MAIL_SHOP_USERNAME', env('MAIL_USERNAME')),
            'password' => env('MAIL_SHOP_PASSWORD', env('MAIL_PASSWORD')),
            'timeout' => null,
            'from' => [
                'address' => env('MAIL_SHOP_USERNAME', env('MAIL_USERNAME', env('MAIL_FROM_ADDRESS', 'hello@example.com'))),
                'name' => env('MAIL_FROM_NAME', 'Taga'),
            ],
            'reply_to' => [
                'address' => env('MAIL_SUPPORT_USERNAME', env('MAIL_FROM_ADDRESS', 'hello@example.com')),
                'name' => env('MAIL_FROM_NAME', 'Taga'),
            ],
        ],

        'support' => [
            'transport' => $mailboxTransport,
            'host' => env('MAIL_SUPPORT_HOST', env('MAIL_HOST', '127.0.0.1')),
            'port' => env('MAIL_SUPPORT_PORT', env('MAIL_PORT', 2525)),
            'encryption' => env('MAIL_SUPPORT_ENCRYPTION', env('MAIL_ENCRYPTION', 'tls')),
            'username' => env('MAIL_SUPPORT_USERNAME', env('MAIL_USERNAME')),
            'password' => env('MAIL_SUPPORT_PASSWORD', env('MAIL_PASSWORD')),
            'timeout' => null,
            'from' => [
                'address' => env('MAIL_SUPPORT_USERNAME', env('MAIL_USERNAME', env('MAIL_FROM_ADDRESS', 'hello@example.com'))),
                'name' => env('MAIL_FROM_NAME', 'Taga'),
            ],
        ],

        'smtp' => [
            'transport' => 'smtp',
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 2525),
            'encryption' => env('MAIL_ENCRYPTION', 'tls'),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'postmark' => [
            'transport' => 'postmark',
            // 'message_stream_id' => env('POSTMARK_MESSAGE_STREAM_ID'),
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],

        'resend' => [
            'transport' => 'resend',
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'smtp',
                'log',
            ],
            'retry_after' => 60,
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => [
                'ses',
                'postmark',
            ],
            'retry_after' => 60,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all emails sent by your application to be sent from
    | the same address. Here you may specify a name and address that is
    | used globally for all emails that are sent by your application.
    |
    */

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => env('MAIL_FROM_NAME', 'Example'),
    ],

];
