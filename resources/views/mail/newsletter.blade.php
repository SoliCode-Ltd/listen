<!DOCTYPE html>
<html lang="{{ ($sections[0]['lang'] ?? 'en') === 'ar' && count($sections) === 1 ? 'ar' : 'en' }}" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{{ $subject }}</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Sans+Arabic:wght@400;500;600&display=swap">
</head>
<body style="margin:0;padding:0;background:#F3F1FA;color:#16161D;">
    <div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;color:#F3F1FA;">
        {{ $preheader }}
        &#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;
    </div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F3F1FA;margin:0;padding:0;">
        <tr>
            <td align="center" style="padding:28px 16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border:1px solid #E6E4F2;border-radius:18px;overflow:hidden;">
                    <tr>
                        <td style="height:5px;background:#7659FA;font-size:0;line-height:0;">&nbsp;</td>
                    </tr>
                    <tr>
                        <td style="padding:28px 32px 8px 32px;">
                            <table role="presentation" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="width:36px;height:36px;background:#7659FA;border-radius:10px;text-align:center;vertical-align:middle;color:#ffffff;font-family:'IBM Plex Sans', 'Segoe UI', Arial, sans-serif;font-size:18px;font-weight:600;line-height:36px;">L</td>
                                    <td style="padding-left:12px;font-family:'IBM Plex Sans', 'Segoe UI', Arial, sans-serif;font-size:16px;font-weight:600;color:#16161D;letter-spacing:0.04em;">{{ $appName }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    @foreach ($sections as $index => $section)
                        <tr>
                            <td dir="{{ $section['dir'] }}" style="padding:{{ $index === 0 ? '18px' : '8px' }} 32px 8px 32px;text-align:{{ $section['align'] }};font-family:{{ $section['font'] }};">
                                @if ($index > 0)
                                    <div style="height:1px;background:#E6E4F2;margin:12px 0 22px 0;"></div>
                                @endif
                                @if ($section['eyebrow'] !== '')
                                    <div style="font-size:12px;font-weight:600;letter-spacing:0.12em;text-transform:uppercase;color:#7659FA;margin:0 0 10px 0;">{{ $section['eyebrow'] }}</div>
                                @endif
                                @if ($section['headline'] !== '')
                                    <h1 style="margin:0 0 14px 0;font-size:28px;line-height:1.25;font-weight:600;color:#12121A;">{{ $section['headline'] }}</h1>
                                @endif
                                <p style="margin:0 0 14px 0;font-size:16px;line-height:1.6;color:#16161D;">{{ $section['greeting'] }}</p>
                                @foreach ($section['paragraphs'] as $paragraph)
                                    <p style="margin:0 0 14px 0;font-size:16px;line-height:1.7;color:#3F3F4A;">{!! $paragraph !!}</p>
                                @endforeach
                                @if ($section['highlights'] !== [])
                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:8px 0 18px 0;background:#F6F4FF;border-radius:14px;">
                                        @foreach ($section['highlights'] as $highlight)
                                            <tr>
                                                <td dir="{{ $section['dir'] }}" style="padding:{{ $loop->first ? '16px' : '0' }} 16px {{ $loop->last ? '16px' : '10px' }} 16px;">
                                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                                        <tr>
                                                            @if ($section['dir'] === 'ltr')
                                                                <td width="28" valign="top" style="width:28px;padding-top:2px;">
                                                                    <div style="width:18px;height:18px;border-radius:9px;background:#7659FA;color:#ffffff;font-size:12px;line-height:18px;text-align:center;font-family:Arial, sans-serif;">&#10003;</div>
                                                                </td>
                                                                <td valign="top" style="font-family:{{ $section['font'] }};font-size:15px;line-height:1.5;color:#16161D;text-align:left;">{{ $highlight }}</td>
                                                            @else
                                                                <td valign="top" style="font-family:{{ $section['font'] }};font-size:15px;line-height:1.5;color:#16161D;text-align:right;">{{ $highlight }}</td>
                                                                <td width="28" valign="top" style="width:28px;padding-top:2px;text-align:left;">
                                                                    <div style="width:18px;height:18px;border-radius:9px;background:#7659FA;color:#ffffff;font-size:12px;line-height:18px;text-align:center;font-family:Arial, sans-serif;">&#10003;</div>
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
                                    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 18px 0;">
                                        <tr>
                                            <td align="{{ $section['align'] === 'right' ? 'right' : 'left' }}" bgcolor="#7659FA" style="border-radius:8px;background:#7659FA;">
                                                <a href="{{ $section['cta_url'] }}" style="display:inline-block;padding:14px 22px;font-family:{{ $section['font'] }};font-size:16px;font-weight:600;line-height:1;color:#ffffff;text-decoration:none;border-radius:8px;">{{ $section['cta_label'] }}</a>
                                            </td>
                                        </tr>
                                    </table>
                                @endif
                                @if ($section['footer'] !== '')
                                    <p style="margin:4px 0 12px 0;font-size:13px;line-height:1.6;color:#71717A;">{{ $section['footer'] }}</p>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    <tr>
                        <td style="padding:8px 32px 28px 32px;">
                            <div style="height:1px;background:#E6E4F2;margin:0 0 14px 0;"></div>
                            @if ($unsubscribeUrl)
                                <p style="margin:0;font-family:'IBM Plex Sans', 'Segoe UI', Arial, sans-serif;font-size:12px;line-height:1.6;color:#71717A;">
                                    <a href="{{ $unsubscribeUrl }}" style="color:#7659FA;text-decoration:underline;">Unsubscribe</a>
                                    <span style="color:#C4C4CE;"> · </span>
                                    <a href="{{ $unsubscribeUrl }}" dir="rtl" style="color:#7659FA;text-decoration:underline;font-family:'IBM Plex Sans Arabic', Tahoma, Arial, sans-serif;">إلغاء الاشتراك</a>
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
