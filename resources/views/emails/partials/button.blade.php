@php use App\Support\EmailStyle as S; @endphp
{{--
    The primary call to action.

    Ink, not vermilion. The storefront's default button variant is
    `primary: 'bg-ink text-paper'` — vermilion is the separate `clay` variant,
    and using it here would make every email a lone accent pop against a warm
    ground, which is not what the product looks like.

    rounded-sm (3px) and h-[3.25rem] px-7 from the lg size. Outlook ignores the
    radius and draws it square, which is a difference nobody will notice.

    Usage: @include('emails.partials.button', ['url' => ..., 'label' => ...])
--}}
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:26px 0 6px 0;">
    <tr>
        <td align="center" bgcolor="{{ S::INK }}" style="background-color:{{ S::INK }}; border-radius:3px;">
            <a href="{{ $url }}"
               style="display:inline-block; padding:15px 28px; font-family:{!! S::FONT !!}; font-size:15px; line-height:20px; font-weight:500; color:{{ S::PAPER }}; text-decoration:none;">{{ $label }}</a>
        </td>
    </tr>
</table>
