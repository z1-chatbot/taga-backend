@php use App\Support\EmailStyle as S; @endphp
{{--
    The shared frame for every Taga email.

    Before this, all 21 templates were standalone and used eight unrelated
    brand colours between them — stock Tailwind gradients, none of them Taga's.

    This is not a look invented for email. Every value comes from
    frontend/src/index.css, including the parts that are easy to get wrong:

      - The mark is "taga" lowercase with a short vermilion rule beneath it.
        The same rule sits above the heading, because on the site the accent
        rule replaced the tracked-out uppercase eyebrow entirely.
      - Body copy is ink-2. Near-black is for headings only.
      - The primary button is ink, not vermilion.
      - Codes and order numbers are Archivo with tabular figures. index.css
        points --font-mono at Archivo as well: there is no monospace here.

    Deliberately absent: gradients (18 of the old templates), box-shadow (15)
    and flexbox (11). Outlook renders none of them, so the old headers arrived
    there as grey slabs.

    Sections: preheader, heading, content, footnote.
--}}
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="x-apple-disable-message-reformatting" />
    <meta name="color-scheme" content="light" />
    <meta name="supported-color-schemes" content="light" />
    <title>@yield('heading', config('app.name'))</title>
    <style type="text/css">
        /* Progressive enhancement only — every colour and dimension the email
           depends on is inlined on the elements themselves. */
        body { margin: 0 !important; padding: 0 !important; width: 100% !important; }
        table { border-collapse: collapse !important; }
        img { border: 0; line-height: 100%; outline: none; text-decoration: none; }
        a { color: {{ S::INK }}; }

        @media only screen and (max-width: 620px) {
            .pad { padding-left: 22px !important; padding-right: 22px !important; }
            .h1 { font-size: 23px !important; line-height: 27px !important; }
            .stack { display: block !important; width: 100% !important; text-align: left !important; }
            .stack-r { display: block !important; width: 100% !important; text-align: left !important; padding-top: 3px !important; }
        }
    </style>
</head>
<body style="margin:0; padding:0; background-color:{{ S::PAPER }};">

    {{-- The line the inbox shows beside the subject. Left empty it fills
         itself from whatever markup comes first, which is rarely flattering. --}}
    <div style="display:none; font-size:1px; color:{{ S::PAPER }}; line-height:1px; max-height:0; max-width:0; opacity:0; overflow:hidden;">
        @yield('preheader')
        &nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:{{ S::PAPER }};">
        <tr>
            <td align="center" style="padding:36px 12px 52px 12px;">

                {{-- Outlook ignores max-width, so it gets a fixed 600 through a
                     conditional "ghost" table. Everything else uses width:100%
                     capped at 600 — the first version pinned width:600px here,
                     which put 650px of content into a 375px phone. --}}
                <!--[if mso]>
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"><tr><td>
                <![endif]-->

                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:600px;">

                    {{-- The mark: lowercase, tightly tracked, vermilion rule beneath. --}}
                    <tr>
                        <td class="pad" style="padding:0 36px 26px 36px;">
                            <div style="font-family:{!! S::FONT !!}; font-size:24px; line-height:24px; font-weight:700; letter-spacing:-0.05em; color:{{ S::INK }};">taga</div>
                            <div style="margin-top:5px; width:26px; height:4px; background-color:{{ S::BRAND }}; font-size:0; line-height:0;">&nbsp;</div>
                        </td>
                    </tr>

                    <tr>
                        <td class="pad" style="padding:0 36px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr><td style="border-top:1px solid {{ S::LINE }}; font-size:0; line-height:0;">&nbsp;</td></tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Body. The accent rule sits above the heading, which is
                         where the site puts it — in place of an eyebrow. --}}
                    <tr>
                        <td class="pad" style="padding:34px 36px 6px 36px;">
                            <div style="width:44px; height:4px; background-color:{{ S::BRAND }}; font-size:0; line-height:0;">&nbsp;</div>
                            <h1 class="h1" style="{!! S::TITLE !!} margin-top:18px; margin-bottom:20px;">@yield('heading')</h1>
                            @yield('content')
                        </td>
                    </tr>

                    <tr>
                        <td class="pad" style="padding:34px 36px 0 36px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr><td style="border-top:1px solid {{ S::LINE }}; font-size:0; line-height:0;">&nbsp;</td></tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td class="pad" style="padding:16px 36px 0 36px;">
                            @hasSection('footnote')
                                <p style="{!! S::SMALL !!}">@yield('footnote')</p>
                            @endif
                            <p style="{!! S::SMALL !!}">
                                Questions? Reply to this email, or write to
                                <a href="mailto:support@taga.ng" style="color:{{ S::INK }}; text-decoration:underline;">support@taga.ng</a>.
                            </p>
                            <p style="{!! S::SMALL !!} margin-bottom:0;">
                                Taga is a platform for licensed Nigerian pharmacies. {{ now()->year }}
                            </p>
                        </td>
                    </tr>

                </table>

                <!--[if mso]>
                </td></tr></table>
                <![endif]-->

            </td>
        </tr>
    </table>

</body>
</html>
