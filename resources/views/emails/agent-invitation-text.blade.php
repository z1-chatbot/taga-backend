Hello {{ $agentName }},

@if($companyName === 'Taga')
Taga has set you up as an independent delivery rider. Sign in with the details below to see the deliveries assigned to you.
@else
{{ $companyName }} has set you up as a delivery rider on Taga. Sign in with the details below to see the deliveries assigned to you.
@endif

Email: {{ $agentEmail }}
Temporary password: {{ $defaultPassword }}

Sign in here: {{ $loginUrl }}

Change this password once you are in — it was generated for you, and anyone
who sees this email can read it. If you did not expect this, ignore it and
let us know.

Questions? Reply to this email, or write to support@taga.ng.

Taga · {{ date('Y') }}
