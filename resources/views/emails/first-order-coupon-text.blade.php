@php
    // Read off the coupon, never restated — the old copy hardcoded "10% off"
    // in five places.
    $discount = $coupon->type === 'percentage'
        ? rtrim(rtrim(number_format((float) $coupon->value, 2, '.', ''), '0'), '.').'% off'
        : '₦'.number_format((float) $coupon->value, 2).' off';
@endphp
Thank you for your first order

Hello {{ $user->name }},

Here is {{ $discount }} your next order, as thanks for shopping with us the first time.

Your discount code: {{ $coupon->code }}

Discount: {{ $discount }}
@if($coupon->min_purchase_amount)
Spend at least: ₦{{ number_format($coupon->min_purchase_amount, 2) }}
@endif
@if($coupon->max_discount_amount)
Up to a maximum of: ₦{{ number_format($coupon->max_discount_amount, 2) }}
@endif
@if($coupon->expires_at)
Use it before: {{ $coupon->expires_at->format('j F Y') }}
@endif
Can be used: Once

Start shopping: {{ \App\Support\AppUrl::storefront('/products') }}

Enter the code at checkout. One use per account.

Questions? Reply to this email, or write to support@taga.ng.

Taga · {{ date('Y') }}
