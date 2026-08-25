Taga

@if ($forReviewer)
A licence is waiting for review

{{ $store->name }} has submitted a pharmacy licence for verification. Nothing
they list is purchasable until this is approved, so the wait is visible to them
as an empty shopfront.

Pharmacy: {{ $store->name }}
Contact: {{ $store->email ?: ($store->owner?->email ?? 'Not on file') }}
Submitted: {{ $store->updated_at?->format('j F Y, g:ia') ?? 'Just now' }}
Status: {{ $isApplicant ? 'New application' : 'Renewal or resubmission' }}

Open the review queue: {{ $queueUrl }}

Sent because a pharmacy licence is awaiting platform review.
@else
We have your pharmacy licence

Your licence has arrived and is with our team. There is nothing further for you
to do. We will email you as soon as it has been checked, whether it is approved
or we need something corrected.

While we check it:
- Over-the-counter and general products: {{ $isApplicant ? 'live once you are approved' : 'still on sale' }}
- Prescription-only medicines: paused until approval
- Controlled substances: paused until approval

Prescription and controlled listings are held back whenever a licence is under
review, including a straightforward renewal. They come back on as soon as the
new licence is approved - you do not need to relist anything.
@if (! $isApplicant)

Open your dashboard: {{ $dashboardUrl }}
@endif

If anything about your licence changes before we get to it, reply to this email
and our team will help.
@endif
