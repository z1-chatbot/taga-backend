@php
    $headings = [
        '1h' => 'You left something in your basket',
        '24h' => 'Your basket is still here',
        '3d' => 'Still want these?',
    ];
    $intros = [
        '1h' => 'These are still in your basket if you would like to finish up.',
        '24h' => 'Your basket is as you left it yesterday. Prices and stock can change, so it is worth checking before you order.',
        '3d' => 'This is the last reminder we will send about this basket.',
    ];
@endphp
{{ $headings[$reminderType] ?? 'Your basket is still here' }}

Hello {{ $user->name }},

{{ $intros[$reminderType] ?? 'Your basket is still waiting.' }}

Your basket
@foreach($cartItems as $item)
- {{ $item['name'] }} — {{ $item['quantity'] }} × ₦{{ number_format($item['price'], 2) }} = ₦{{ number_format($item['price'] * $item['quantity'], 2) }}
@endforeach

Basket total: ₦{{ number_format($cartTotal, 2) }}

Go to your basket: {{ \App\Support\AppUrl::storefront('/cart') }}

Prescription items still need a valid script before they can be dispensed.

Unsubscribe from these: {{ \App\Support\AppUrl::storefront('/unsubscribe') }}

Taga · {{ date('Y') }}
