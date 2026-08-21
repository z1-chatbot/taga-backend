@php
    use App\Support\EmailStyle as S;

    $change = $reportData['comparison']['revenue_change'] ?? null;

    $movement = $change === null ? null : match (true) {
        $change > 0 => '<span style="color:'.S::SUCCESS.'; font-weight:500;">Up '.number_format($change, 1).'% on yesterday</span>',
        $change < 0 => '<span style="color:'.S::DANGER.'; font-weight:500;">Down '.number_format(abs($change), 1).'% on yesterday</span>',
        default => 'Level with yesterday',
    };

    $topProducts = collect($reportData['top_products'] ?? [])->map(fn ($product) => [
        'title' => $product['name'],
        'meta' => $product['quantity'].' sold',
        'value' => '&#8358;'.number_format($product['revenue'], 2),
    ])->all();

    $byStatus = collect($reportData['orders_by_status'] ?? [])->map(fn ($data, $status) => [
        'title' => ucfirst(str_replace('_', ' ', $status)),
        'meta' => $data['count'].' '.($data['count'] === 1 ? 'order' : 'orders'),
        'value' => '&#8358;'.number_format($data['revenue'], 2),
    ])->values()->all();
@endphp

@extends('emails.layout')

@section('preheader', 'Revenue ₦' . number_format($reportData['total_revenue'], 2) . ' across ' . $reportData['total_orders'] . ' orders.')
@section('heading', 'Sales on ' . $reportDate)

@section('content')

    @include('emails.partials.rows', [
        'rows' => array_filter([
            'Revenue' => '<span style="'.S::PRICE.'">&#8358;'.number_format($reportData['total_revenue'], 2).'</span>',
            'Compared with yesterday' => $movement,
            'Orders' => number_format($reportData['total_orders']),
            'Average order' => '&#8358;'.number_format($reportData['average_order_value'], 2),
            'Items sold' => number_format($reportData['total_items_sold']),
            'New customers' => isset($reportData['new_customers'])
                ? number_format($reportData['new_customers'])
                : null,
            'Returning customers' => isset($reportData['returning_customers'])
                ? number_format($reportData['returning_customers'])
                : null,
        ]),
    ])

    @if ($byStatus)
        <p style="{!! S::SUBTITLE !!} margin-top:30px;">Orders by status</p>
        @include('emails.partials.lines', ['lines' => $byStatus, 'card' => false])
    @endif

    @if ($topProducts)
        <p style="{!! S::SUBTITLE !!} margin-top:30px;">Best sellers</p>
        @include('emails.partials.lines', ['lines' => $topProducts])
    @endif

    @include('emails.partials.button', [
        'url' => \App\Support\AppUrl::admin('/dashboard'),
        'label' => 'Open the dashboard',
    ])

@endsection

@section('footnote')
    {{-- The old footer offered to let you "adjust the delivery time", which
         nothing read — the send time is fixed in routes/console.php. --}}
    Sent every morning at 08:00. Recipients are set on the Email Automation page.
@endsection
