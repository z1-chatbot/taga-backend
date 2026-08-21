Taga

We have your request

Thank you, {{ $consultation->name }}. Your request to speak to a
{{ strtolower($practitioner) }} is with our team.

Your reference: {{ $consultation->reference }}
Quote it if you get in touch about this request.

You asked to see: {{ $practitioner }}
Contact you by: {{ ucfirst($consultation->preferred_contact) }}
@if ($consultation->preferred_time)
Best time: {{ $consultation->preferred_time }}
@endif
Raised on: {{ $consultation->created_at?->format('j F Y, g:ia') }}

What happens next

Someone will read your request and reply with the next step - usually a time to
talk, or a question or two first. Replies arrive by email and are also kept on
this request, so the whole conversation stays in one place.

View your request: {{ $trackUrl }}

This is not a medical emergency service. If this is urgent, please go to your
nearest hospital or call your local emergency number.

You can reply to this email and it will reach the same team.
