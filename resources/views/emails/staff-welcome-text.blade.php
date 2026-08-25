Taga

Your staff account is ready

Hello {{ $user->name }},

You have been given access to the Taga admin dashboard. Sign in with the
details below.

Email: {{ $user->email }}
Temporary password: {{ $password }}
Role: {{ $user->roleRelation->display_name ?? ucfirst($user->role) }}

Sign in to the dashboard: {{ $loginUrl }}

Change this password once you are in - it was generated for you, and anyone who
sees this email can read it. Never share your sign-in details with anyone,
including colleagues.

You are receiving this because an administrator created a staff account for you.
