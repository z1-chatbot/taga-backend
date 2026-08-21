Welcome to Taga

Hello {{ $user->name }},

Taga is a marketplace for licensed Nigerian pharmacies. Every store is verified
against its premises licence before it can list anything, and prescription items
are released only after a pharmacist has reviewed the script.
@if($couponCode)

Your welcome code: {{ $couponCode }}
Enter it at checkout.
@endif

Start shopping: {{ \App\Support\AppUrl::storefront('/products') }}

You are receiving this because an account was created with this email address.

Questions? Reply to this email, or write to support@taga.ng.

Taga · {{ date('Y') }}
