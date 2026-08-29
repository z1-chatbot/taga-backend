Taga

Someone is waiting

Hello {{ $practitionerName }},

A request to speak to a {{ strtolower($specialty) }} came in and is waiting for
somebody to pick it up.

Reference: {{ $consultation->reference }}
Specialty: {{ $specialty }}
Subject: {{ $consultation->subject }}
@if ($consultation->priority !== 'normal')
Priority: {{ ucfirst($consultation->priority) }}
@endif
Raised: {{ $consultation->created_at?->format('j F Y, g:ia') }}

Open the request: {{ $queueUrl }}

Everyone covering {{ strtolower($specialty) }} received this. The first to reply
takes it on, and it will then show as yours to the rest of them - so if a
colleague has already opened it, you will see their name against it rather than
a second reply box.

The person's message is not in this email. Only whoever takes the request on
needs to read it.

You are receiving this because you cover {{ strtolower($specialty) }} consultations on Taga.
