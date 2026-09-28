<!DOCTYPE html>
<html lang="{{ ($sections[0]['lang'] ?? 'en') === 'ar' && count($sections) === 1 ? 'ar' : 'en' }}" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{{ $subject }}</title>
</head>
<body style="margin:0;padding:0;background:#F3F1FA;color:#16161D;">
    <div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;color:#F3F1FA;">
        {{ $preheader }}
        &#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;
    </div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F3F1FA;margin:0;padding:0;">
        <tr>
            <td align="center" style="padding:40px 16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border:1px solid #E4E0F2;border-radius:20px;overflow:hidden;">
                    <tr>
                        <td style="height:4px;background:#7659FA;font-size:0;line-height:0;">&nbsp;</td>
                    </tr>
                    <tr>
                        <td style="padding:32px 36px 8px 36px;background:#ffffff;">
                            <table role="presentation" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td valign="middle" style="padding-right:12px;">
                                        @include('mail.partials.waveform')
                                    </td>
                                    <td valign="middle" style="font-family:'IBM Plex Sans', 'Segoe UI', Arial, sans-serif;font-size:20px;font-weight:700;letter-spacing:-0.03em;color:#14141C;line-height:1;">{{ $appName }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    @foreach ($sections as $index => $section)
                        <tr>
                            <td dir="{{ $section['dir'] }}" style="padding:{{ $index === 0 ? '20px' : '4px' }} 36px 8px 36px;text-align:{{ $section['align'] }};font-family:{{ $section['font'] }};">
                                @if ($index > 0)
                                    <div style="height:1px;background:#E8E5F3;margin:8px 0 28px 0;"></div>
                                @endif
                                @if ($section['eyebrow'] !== '')
                                    <div style="font-size:11px;font-weight:600;letter-spacing:0.16em;text-transform:uppercase;color:#7659FA;margin:0 0 12px 0;">{{ $section['eyebrow'] }}</div>
                                @endif
                                @if ($section['headline'] !== '')
                                    <h1 style="margin:0 0 16px 0;font-size:30px;line-height:1.2;font-weight:600;letter-spacing:-0.03em;color:#12121A;">{{ $section['headline'] }}</h1>
                                @endif
                                <p style="margin:0 0 14px 0;font-size:16px;line-height:1.6;color:#24242C;">{{ $section['greeting'] }}</p>
                                @foreach ($section['paragraphs'] as $paragraph)
                                    <p style="margin:0 0 14px 0;font-size:16px;line-height:1.75;color:#4A4A55;">{!! $paragraph !!}</p>
                                @endforeach
                                @if ($section['highlights'] !== [])
                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:18px 0 8px 0;background:#F7F5FF;border:1px solid #E8E4F8;border-radius:16px;">
                                        @foreach ($section['highlights'] as $highlight)
                                            <tr>
                                                <td dir="{{ $section['dir'] }}" style="padding:{{ $loop->first ? '8px' : '0' }} 8px {{ $loop->last ? '8px' : '0' }} 8px;">
                                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                                        <tr>
                                                            @if ($section['dir'] === 'ltr')
                                                                <td width="22" valign="top" style="width:22px;padding:14px 0 14px 12px;">
                                                                    <div style="width:8px;height:8px;margin-top:6px;border-radius:4px;background:#7659FA;font-size:0;line-height:0;">&nbsp;</div>
                                                                </td>
                                                                <td valign="top" style="padding:12px 16px 12px 0;font-family:{{ $section['font'] }};font-size:15px;line-height:1.55;color:#1C1C24;text-align:left;">{{ $highlight }}</td>
                                                            @else
                                                                <td valign="top" style="padding:12px 0 12px 16px;font-family:{{ $section['font'] }};font-size:15px;line-height:1.55;color:#1C1C24;text-align:right;">{{ $highlight }}</td>
                                                                <td width="22" valign="top" style="width:22px;padding:14px 12px 14px 0;text-align:left;">
                                                                    <div style="width:8px;height:8px;margin-top:6px;margin-left:auto;border-radius:4px;background:#7659FA;font-size:0;line-height:0;">&nbsp;</div>
                                                                </td>
                                                            @endif
                                                        </tr>
                                                    </table>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </table>
                                @endif
                                @if ($section['cta_label'] !== '' && $section['cta_url'] !== '')
                                    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:22px 0 8px 0;">
                                        <tr>
                                            <td align="{{ $section['align'] === 'right' ? 'right' : 'left' }}" bgcolor="#7659FA" style="border-radius:12px;background:#7659FA;">
                                                <a href="{{ $section['cta_url'] }}" style="display:inline-block;padding:15px 26px;font-family:{{ $section['font'] }};font-size:15px;font-weight:600;line-height:1.2;letter-spacing:-0.01em;color:#ffffff;text-decoration:none;border-radius:12px;">{{ $section['cta_label'] }}</a>
                                            </td>
                                        </tr>
                                    </table>
                                @endif
                                @if ($section['footer'] !== '')
                                    <p style="margin:18px 0 8px 0;font-size:13px;line-height:1.6;color:#71717A;">{{ $section['footer'] }}</p>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    <tr>
                        <td style="padding:12px 36px 28px 36px;background:#FAF9FD;">
                            <div style="height:1px;background:#E8E5F3;margin:0 0 16px 0;"></div>
                            <p style="margin:0 0 8px 0;font-family:'IBM Plex Sans', 'Segoe UI', Arial, sans-serif;font-size:12px;line-height:1.5;color:#8A8A96;">Listen · منصة إسمع</p>
                            @if ($unsubscribeUrl)
                                <p style="margin:0;font-family:'IBM Plex Sans', 'Segoe UI', Arial, sans-serif;font-size:12px;line-height:1.6;color:#71717A;">
                                    <a href="{{ $unsubscribeUrl }}" style="color:#5B45C6;text-decoration:underline;">Unsubscribe</a>
                                    <span style="color:#C4C4CE;"> · </span>
                                    <a href="{{ $unsubscribeUrl }}" dir="rtl" style="color:#5B45C6;text-decoration:underline;font-family:'IBM Plex Sans Arabic', Tahoma, Arial, sans-serif;">إلغاء الاشتراك</a>
                                </p>
                            @elseif ($preview)
                                <p style="margin:0;font-family:'IBM Plex Sans', 'Segoe UI', Arial, sans-serif;font-size:12px;line-height:1.6;color:#71717A;">Unsubscribe · إلغاء الاشتراك</p>
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
