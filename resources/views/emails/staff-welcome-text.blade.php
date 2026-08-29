@php
    $isPractitioner = ($user->roleRelation->name ?? $user->role) === 'practitioner';
    $specialties = $isPractitioner
        ? $user->practitionerTypes->pluck('label')->implode(', ')
        : null;
@endphp
Taga

Your staff account is ready

Hello {{ $user->name }},

@if ($isPractitioner)
You have been set up to answer consultation requests on Taga. Sign in with the
details below.
@else
You have been given access to the Taga admin dashboard. Sign in with the
details below.
@endif

Email: {{ $user->email }}
Temporary password: {{ $password }}
Role: {{ $user->roleRelation->display_name ?? ucfirst($user->role) }}
@if ($specialties)
You answer for: {{ $specialties }}
@endif

@if ($isPractitioner)
Open the consultation queue: {{ $loginUrl }}

Requests in your specialties reach everyone who covers them, so you will see
colleagues' requests alongside your own. The first person to reply takes a
request on, and it then shows as theirs to the rest.
@else
Sign in to the dashboard: {{ $loginUrl }}
@endif

Change this password once you are in - it was generated for you, and anyone who
sees this email can read it. Never share your sign-in details with anyone,
including colleagues.

You are receiving this because an administrator created a staff account for you.
