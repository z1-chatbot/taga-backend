Taga

@if ($approved)
{{ $store->name }} is verified

@if ($isApplicant)
Your pharmacy licence has been checked and approved, and your dashboard is now
open. Sign in with the same email and password you use on Taga, add your
products, and they go on sale straight away.
@else
Your pharmacy licence has been checked and approved. Your listings are now live,
and customers can buy from you.
@endif

What you can sell:
- Over-the-counter medicines and general products: yes
- Prescription-only medicines: {{ $store->can_sell_prescription ? 'yes' : 'not yet enabled' }}
- Controlled substances: {{ $store->can_sell_controlled ? 'yes' : 'not enabled' }}
@if ($store->pharmacy_license_expiry)

Your licence is on file until {{ $store->pharmacy_license_expiry->format('j F Y') }}.
Send us a renewal before then so your listings stay up.
@endif

Open your dashboard: {{ $dashboardUrl }}
@else
We could not verify {{ $store->name }}

@if ($isApplicant)
We have reviewed the pharmacy licence you sent and cannot approve it as it
stands. Nothing you entered has been lost - the form still has your details
when you go back to it.
@else
We have reviewed the pharmacy licence you submitted and cannot approve it as it
stands. Your store and its products are saved - nothing has been deleted - but
they are not on sale.
@endif
@if ($reason)

Reason: {{ $reason }}
@endif
@if ($isApplicant)

Correct what is noted above and send it again: {{ $applyUrl }}
@else

You can submit a corrected licence from your dashboard and we will look at it
again: {{ $dashboardUrl }}
@endif
@endif

If you have questions about this decision, reply to this email and our team will help.
