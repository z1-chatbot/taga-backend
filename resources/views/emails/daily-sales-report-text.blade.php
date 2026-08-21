📊 DAILY SALES REPORT
{{ $reportDate }}

SALES OVERVIEW:
---
Total Revenue: ₦{{ number_format($reportData['total_revenue'], 2) }}
Orders: {{ $reportData['total_orders'] }}
Average Order Value: ₦{{ number_format($reportData['average_order_value'], 2) }}
Items Sold: {{ $reportData['total_items_sold'] }}

@if(!empty($reportData['comparison']))
📈 COMPARED TO YESTERDAY:
@if($reportData['comparison']['revenue_change'] > 0)
+{{ number_format($reportData['comparison']['revenue_change'], 1) }}% revenue (UP)
@elseif($reportData['comparison']['revenue_change'] < 0)
{{ number_format($reportData['comparison']['revenue_change'], 1) }}% revenue (DOWN)
@else
No change in revenue
@endif

@endif
ORDER STATUS BREAKDOWN:
---
@foreach($reportData['orders_by_status'] as $status => $data)
{{ ucfirst(str_replace('_', ' ', $status)) }}: {{ $data['count'] }} orders - ₦{{ number_format($data['revenue'], 2) }}
@endforeach

@if(!empty($reportData['top_products']))
TOP SELLING PRODUCTS:
---
@foreach($reportData['top_products'] as $product)
{{ $product['name'] }}
Units Sold: {{ $product['quantity'] }} - Revenue: ₦{{ number_format($product['revenue'], 2) }}

@endforeach
@endif

@if(!empty($reportData['new_customers']))
CUSTOMER INSIGHTS:
---
New Customers: {{ $reportData['new_customers'] }}
Returning Customers: {{ $reportData['returning_customers'] }}

@endif
View Full Dashboard: {{ \App\Support\AppUrl::admin() }}/dashboard

This is an automated daily sales summary. You can adjust the delivery time and recipients in your email automation settings.

---
Taga
Sales Analytics & Reporting
© {{ date('Y') }} Taga. All rights reserved.
