Taga

A reply to your request

{{ $reply->author_name }} has replied to your request to speak to a
{{ strtolower($practitioner) }}.

{{ $reply->body }}

Reference: {{ $consultation->reference }}
About: {{ $practitioner }}
Status: {{ ucfirst(str_replace('_', ' ', $consultation->status)) }}
@if ($consultation->scheduled_at)
Appointment: {{ $consultation->scheduled_at->format('j F Y, g:ia') }}
@endif

Open the conversation: {{ $trackUrl }}

This is not a medical emergency service. If this is urgent, please go to your
nearest hospital or call your local emergency number.

Reply to this email, or write back on the request itself - both reach the same team.
