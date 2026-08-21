Reset your password

Hello {{ $user->name }},

We received a request to reset the password on your Taga account. Open the link below to choose a new one:

{{ $resetUrl }}

This link expires in {{ $expiresInMinutes }} minutes and can only be used once.

If you did not request a password reset, you can ignore this email — your password will not change.

----
Taga
{{ date('Y') }} Taga. All rights reserved.
