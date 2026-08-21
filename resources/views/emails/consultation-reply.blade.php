@php use App\Support\EmailStyle as S; @endphp
@extends('emails.layout')

@section('preheader', 'A reply on your consultation request ' . $consultation->reference)

@section('heading', 'A reply to your request')

@section('content')

    <p style="{!! S::LEAD !!}">
        {{ $reply->author_name }} has replied to your request to speak to a
        {{ strtolower($practitioner) }}.
    </p>

    {{-- The reply itself, set apart. paper-2 is the system's recessed tint, and
         nl2br keeps the writer's paragraphs — support replies are written in a
         textarea, not a rich editor. --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:22px 0 6px 0;">
        <tr>
            <td align="left" bgcolor="{{ S::PAPER_2 }}" style="background-color:{{ S::PAPER_2 }}; border:1px solid {{ S::LINE }}; border-radius:4px; padding:18px 22px;">
                <div style="{!! S::BODY !!} margin:0;">{!! nl2br(e($reply->body)) !!}</div>
            </td>
        </tr>
    </table>

    @include('emails.partials.rows', [
        'rows' => array_filter([
            'Reference' => e($consultation->reference),
            'About' => e($practitioner),
            'Status' => e(ucfirst(str_replace('_', ' ', $consultation->status))),
            'Appointment' => $consultation->scheduled_at?->format('j F Y, g:ia'),
        ]),
    ])

    @include('emails.partials.button', [
        'url' => $trackUrl,
        'label' => 'Open the conversation',
    ])

    <p style="{!! S::SMALL !!} margin-top:18px;">
        This is not a medical emergency service. If this is urgent, please go to your
        nearest hospital or call your local emergency number.
    </p>

@endsection

@section('footnote')
    Reply to this email, or write back on the request itself — both reach the same team.
@endsection
