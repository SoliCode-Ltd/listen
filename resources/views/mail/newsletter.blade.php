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
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border:1px solid #E4E0F2;border-radius:24px;overflow:hidden;">
                    <tr>
                        <td style="height:4px;background:#7659FA;font-size:0;line-height:0;">&nbsp;</td>
                    </tr>
                    <tr>
                        <td align="center" style="padding:36px 40px 8px 40px;background:#ffffff;text-align:center;">
                            <img src="{{ $logoUrl }}" alt="{{ $logoAlt }}" width="188" height="60" style="display:block;margin:0 auto;border:0;outline:none;text-decoration:none;width:188px;max-width:188px;height:auto;">
                            <p style="margin:14px 0 0 0;font-family:'IBM Plex Sans', 'Segoe UI', Arial, sans-serif;font-size:13px;line-height:1.4;letter-spacing:0.14em;color:#6E6A80;text-align:center;">{{ $siteName }}</p>
                        </td>
                    </tr>
                    @foreach ($sections as $index => $section)
                        <tr>
                            <td dir="{{ $section['dir'] }}" align="center" style="padding:{{ $index === 0 ? '28px' : '8px' }} 40px 8px 40px;font-family:{{ $section['font'] }};">
                                @if ($index > 0)
                                    <div style="height:1px;background:#E8E5F3;margin:12px auto 28px auto;"></div>
                                @endif
                                @if ($section['eyebrow'] !== '')
                                    <div style="font-size:11px;font-weight:600;letter-spacing:0.18em;text-transform:uppercase;color:#7659FA;margin:0 0 14px 0;text-align:center;">{{ $section['eyebrow'] }}</div>
                                @endif
                                @if ($section['headline'] !== '')
                                    <h1 style="margin:0 auto 18px auto;max-width:440px;font-size:30px;line-height:1.25;font-weight:600;letter-spacing:-0.03em;color:#12121A;text-align:center;">{{ $section['headline'] }}</h1>
                                @endif
                                <p dir="{{ $section['dir'] }}" style="margin:0 auto 14px auto;max-width:440px;font-size:16px;line-height:1.6;color:#24242C;text-align:center;">{{ $section['greeting'] }}</p>
                                @foreach ($section['paragraphs'] as $paragraph)
                                    <p dir="{{ $section['dir'] }}" style="margin:0 auto 14px auto;max-width:440px;font-size:16px;line-height:1.75;color:#4A4A55;text-align:center;">{!! $paragraph !!}</p>
                                @endforeach
                                @if ($section['highlights'] !== [])
                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:20px auto 8px auto;max-width:440px;background:#F7F5FF;border:1px solid #E8E4F8;border-radius:16px;">
                                        @foreach ($section['highlights'] as $highlight)
                                            <tr>
                                                <td dir="{{ $section['dir'] }}" align="center" style="padding:{{ $loop->first ? '16px' : '0' }} 20px {{ $loop->last ? '16px' : '8px' }} 20px;font-family:{{ $section['font'] }};font-size:15px;line-height:1.55;color:#1C1C24;text-align:center;">{{ $highlight }}</td>
                                            </tr>
                                        @endforeach
                                    </table>
                                @endif
                                @if ($section['cta_label'] !== '' && $section['cta_url'] !== '')
                                    <table role="presentation" cellpadding="0" cellspacing="0" align="center" style="margin:24px auto 8px auto;">
                                        <tr>
                                            <td align="center" bgcolor="#7659FA" style="border-radius:12px;background:#7659FA;">
                                                <a href="{{ $section['cta_url'] }}" style="display:inline-block;padding:15px 28px;font-family:{{ $section['font'] }};font-size:15px;font-weight:600;line-height:1.2;letter-spacing:-0.01em;color:#ffffff;text-decoration:none;border-radius:12px;">{{ $section['cta_label'] }}</a>
                                            </td>
                                        </tr>
                                    </table>
                                @endif
                                @if ($section['footer'] !== '')
                                    <p dir="{{ $section['dir'] }}" style="margin:18px auto 8px auto;max-width:440px;font-size:13px;line-height:1.6;color:#71717A;text-align:center;">{{ $section['footer'] }}</p>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    <tr>
                        <td align="center" style="padding:20px 40px 32px 40px;background:#FAF9FD;text-align:center;">
                            <div style="height:1px;background:#E8E5F3;margin:0 auto 16px auto;"></div>
                            <p style="margin:0 0 8px 0;font-family:'IBM Plex Sans', 'Segoe UI', Arial, sans-serif;font-size:12px;line-height:1.5;letter-spacing:0.12em;color:#8A8A96;text-align:center;">{{ $siteName }}</p>
                            @if ($unsubscribeUrlEn && $unsubscribeUrlAr)
                                <p style="margin:0;font-family:'IBM Plex Sans', 'Segoe UI', Arial, sans-serif;font-size:12px;line-height:1.6;color:#71717A;text-align:center;">
                                    <a href="{{ $unsubscribeUrlEn }}" style="color:#5B45C6;text-decoration:underline;">Unsubscribe</a>
                                    <span style="color:#C4C4CE;"> · </span>
                                    <a href="{{ $unsubscribeUrlAr }}" dir="rtl" style="color:#5B45C6;text-decoration:underline;font-family:'IBM Plex Sans Arabic', Tahoma, Arial, sans-serif;">إلغاء الاشتراك</a>
                                </p>
                            @elseif ($preview)
                                <p style="margin:0;font-family:'IBM Plex Sans', 'Segoe UI', Arial, sans-serif;font-size:12px;line-height:1.6;color:#71717A;text-align:center;">Unsubscribe · إلغاء الاشتراك</p>
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
