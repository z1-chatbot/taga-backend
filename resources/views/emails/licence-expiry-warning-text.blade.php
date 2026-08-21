Taga

@if ($expired)
{{ $store->name }} has stopped selling

The pharmacy licence we hold for your store expired on
{{ $store->pharmacy_license_expiry->format('j F Y') }}, so your products are no
longer on sale.

Nothing has been deleted. Your shop, your products and your order history are all
exactly as you left them - they go back on sale as soon as we have approved a
current licence.

Submit your renewed licence: {{ $dashboardUrl }}
@else
@if ($daysRemaining === 0)
Your pharmacy licence expires today
@else
Your pharmacy licence expires in {{ $daysRemaining }} {{ $daysRemaining === 1 ? 'day' : 'days' }}
@endif

The licence we hold for {{ $store->name }} expires on
{{ $store->pharmacy_license_expiry->format('j F Y') }}.

When it expires your products stop being sold on Taga until we have approved a
current licence. Send us your renewal before then and nothing will be interrupted.

Submit your renewed licence: {{ $dashboardUrl }}
@endif

If you have already renewed, reply to this email and we will look into it.
