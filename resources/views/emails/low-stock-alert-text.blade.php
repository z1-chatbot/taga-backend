⚠️ LOW STOCK ALERT

{{ count($lowStockProducts) }} product(s) need immediate attention

ACTION NEEDED: these products are at or below {{ $threshold }} units in stock. Restock soon to avoid running out.

PRODUCTS REQUIRING RESTOCK:
---
@foreach($lowStockProducts as $product)

{{ $product['name'] }}
@if(!empty($product['sku']))
SKU: {{ $product['sku'] }}
@endif
Current Stock: {{ $product['stock'] }} units
Status: @if($product['stock'] == 0)OUT OF STOCK @elseif($product['stock'] <= 3)CRITICAL @else LOW @endif

@endforeach

💡 RECOMMENDATION: Review your inventory and place restock orders for these products to maintain optimal stock levels and avoid lost sales.

Manage Inventory: {{ \App\Support\AppUrl::admin() }}/products

Note: This is an automated alert, sent once a day when stock reaches {{ $threshold }} units or fewer. The threshold is set by the Taga team.

---
Taga
Inventory Management System
© {{ date('Y') }} Taga. All rights reserved.
