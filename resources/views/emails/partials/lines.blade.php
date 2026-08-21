@php use App\Support\EmailStyle as S; @endphp
{{--
    A list of things — order items, products low on stock, top sellers.

    Optionally wrapped in a card, which index.css keeps for "the few genuinely
    panel-like surfaces (order summary, dispensing table)". Pass card => false
    for a plain ruled list, which is the default layout unit everywhere else.

    Each line: ['title' => …, 'meta' => …, 'value' => …]. meta and value are
    optional. On a narrow screen the value drops beneath the title rather than
    being squeezed into a column too narrow to hold a price.

    Usage:
      @include('emails.partials.lines', ['lines' => [...], 'card' => true])
--}}
@php($card = $card ?? true)
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:16px 0 0 0;">
    <tr>
        <td @if($card) bgcolor="{{ S::CARD }}" style="background-color:{{ S::CARD }}; border:1px solid {{ S::LINE }}; border-radius:4px; padding:4px 20px 8px 20px;" @else style="padding:0;" @endif>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                @foreach ($lines as $line)
                    <tr>
                        <td style="padding:13px 0; {{ $loop->first ? ($card ? '' : 'border-top:1px solid '.S::LINE.';') : 'border-top:1px solid '.S::LINE.';' }}">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td class="stack" align="left" valign="top" style="font-family:{!! S::FONT !!}; font-size:15px; line-height:22px; color:{{ S::INK }};">
                                        {{ $line['title'] }}
                                        @if (! empty($line['meta']))
                                            <div style="{!! S::DATA !!} margin-top:2px;">{!! $line['meta'] !!}</div>
                                        @endif
                                    </td>
                                    @if (isset($line['value']))
                                        <td class="stack-r" align="right" valign="top" style="{!! S::PRICE !!}">{!! $line['value'] !!}</td>
                                    @endif
                                </tr>
                            </table>
                        </td>
                    </tr>
                @endforeach
                @unless ($card)
                    <tr><td style="border-top:1px solid {{ S::LINE }}; font-size:0; line-height:0;">&nbsp;</td></tr>
                @endunless
            </table>
        </td>
    </tr>
</table>
