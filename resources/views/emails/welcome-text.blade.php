Welcome to Taga

Hello {{ $user->name }},

Taga is a platform for licensed Nigerian pharmacies. Every store is verified
against its premises licence before it can list anything, and a pharmacist reviews
your prescription before any prescription-only medicine is released.
@if($couponCode)

Your welcome code: {{ $couponCode }}
Enter it at checkout.
@endif

Start shopping: {{ \App\Support\AppUrl::storefront('/products') }}

You are receiving this because an account was created with this email address.

Questions? Reply to this email, or write to support@taga.ng.

Taga · {{ date('Y') }}
