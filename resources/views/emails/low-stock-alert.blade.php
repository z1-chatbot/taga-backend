@php
    use App\Support\EmailStyle as S;

    $count = count($lowStockProducts);

    $lines = collect($lowStockProducts)->map(function ($product) {
        $stock = (int) $product['stock'];

        [$word, $colour] = match (true) {
            $stock === 0 => ['Out of stock', S::DANGER],
            $stock <= 3 => ['Critical', S::DANGER],
            default => ['Low', S::WARN],
        };

        return [
            'title' => $product['name'],
            'meta' => (! empty($product['sku']) ? e($product['sku']).' &middot; ' : '')
                .'<span style="color:'.$colour.';">'.$word.'</span>',
            'value' => $stock.' left',
        ];
    })->all();
@endphp

@extends('emails.layout')

@section('preheader', $count . ' ' . ($count === 1 ? 'product needs' : 'products need') . ' restocking.')
@section('heading', $count === 1 ? 'One product needs restocking' : $count . ' products need restocking')

@section('content')

    <p style="{!! S::LEAD !!}">
        @if (! empty($storeName))
            These items at {{ $storeName }} are at or below {{ $threshold }} in stock.
        @else
            These items are at or below {{ $threshold }} in stock.
        @endif
    </p>

    @include('emails.partials.lines', ['lines' => $lines])

    @include('emails.partials.button', [
        'url' => \App\Support\AppUrl::admin('/products'),
        'label' => 'Update stock levels',
    ])

@endsection

@section('footnote')
    Sent once a day when stock reaches {{ $threshold }} or fewer. The threshold is on the
    Settings page, under General.
@endsection
