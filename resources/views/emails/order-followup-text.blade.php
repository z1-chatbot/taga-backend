How did your order go?

Hello {{ $order->user->name ?? 'there' }},

Order {{ $order->order_number }} was delivered on {{ $order->updated_at->format('j F Y') }}.
If you have a minute, a short review helps the next person choose — and tells the
pharmacy how they did.

What you ordered
@foreach($order->items as $item)
- {{ $item->product_name }} — quantity {{ $item->quantity }}
@endforeach

Write a review: {{ \App\Support\AppUrl::storefront('/orders/'.$order->id.'/review') }}

We send this once, a week after delivery.

Unsubscribe from these: {{ \App\Support\AppUrl::storefront('/unsubscribe') }}

Taga · {{ date('Y') }}
