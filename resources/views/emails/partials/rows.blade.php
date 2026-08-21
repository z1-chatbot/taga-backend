@php use App\Support\EmailStyle as S; @endphp
{{--
    A ruled list. index.css calls .row "the primary layout unit, replacing card
    grids", so this is the default way to show structured detail — and a
    hairline is also the one such device every mail client draws faithfully.

    Values are set in .t-data: same family as everything else, with tabular
    figures doing the aligning. index.css is explicit that this is not a job
    for a monospace face, and points --font-mono at Archivo to make the point.

    $rows is a label => value map; values may contain markup.

    Usage: @include('emails.partials.rows', ['rows' => ['Order' => 'TG-10428']])
--}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:22px 0 4px 0;">
    @foreach ($rows as $label => $value)
        <tr>
            <td style="padding:12px 0; border-top:1px solid {{ S::LINE }};">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td class="stack" width="42%" align="left" valign="top" style="{!! S::LABEL !!}">{{ $label }}</td>
                        <td class="stack-r" width="58%" align="right" valign="top" style="{!! S::DATA !!}">{!! $value !!}</td>
                    </tr>
                </table>
            </td>
        </tr>
    @endforeach
    <tr><td style="border-top:1px solid {{ S::LINE }}; font-size:0; line-height:0;">&nbsp;</td></tr>
</table>
