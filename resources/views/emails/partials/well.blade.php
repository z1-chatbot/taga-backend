@php use App\Support\EmailStyle as S; @endphp
{{--
    A recessed area. index.css reserves paper-2 for exactly this — "image wells
    and recessed areas only" — so it is the one tint in the system that a piece
    of set-apart content is entitled to.

    Used for the delivery code: a number somebody reads aloud at their door,
    which is the one thing in an order email that has to be findable at a
    glance. Still Archivo with tabular figures, not a monospace face.

    Usage: @include('emails.partials.well', ['label' => ..., 'value' => ...])
--}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:22px 0 6px 0;">
    <tr>
        <td align="left" bgcolor="{{ S::PAPER_2 }}" style="background-color:{{ S::PAPER_2 }}; border:1px solid {{ S::LINE }}; border-radius:4px; padding:18px 22px;">
            <div style="{!! S::LABEL !!}">{{ $label }}</div>
            <div style="{!! S::CODE !!} margin-top:6px;">{{ $value }}</div>
        </td>
    </tr>
</table>
