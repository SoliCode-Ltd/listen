@php
    $font = \App\Services\Newsletter\NewsletterRenderer::FONT_EN;
    $fontAr = \App\Services\Newsletter\NewsletterRenderer::FONT_AR;
    $wave = [4, 8, 14, 8, 5, 12, 20, 12, 6, 16, 26, 16, 7, 18, 11, 5, 13, 8, 4];
@endphp
<!DOCTYPE html>
<html lang="{{ ($sections[0]['lang'] ?? 'en') === 'ar' && count($sections) === 1 ? 'ar' : 'en' }}" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{{ $subject }}</title>
    {{-- Emails keep their own fonts (NewsletterRenderer::FONT_*), not the website font setting. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700;800&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body style="margin:0;padding:0;background:#ECEAF3;color:#16161D;font-family:{{ $font }};">
    <div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;color:#ECEAF3;">
        {{ $preheader }}
        &#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;
    </div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ECEAF3;margin:0;padding:0;">
        <tr>
            <td align="center" style="padding:32px 12px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:20px;">
                    <tr>
                        <td align="center" bgcolor="#0B0B0F" style="background:#0B0B0F;padding:36px 28px 22px 28px;text-align:center;">
                            <img src="{{ $logoUrl }}" alt="{{ $logoAlt }}" width="168" height="54" style="display:block;margin:0 auto;border:0;outline:none;text-decoration:none;width:168px;max-width:100%;height:auto;">
                            <p style="margin:10px 0 0 0;font-family:{{ $font }};font-size:12px;line-height:1.4;letter-spacing:0.18em;color:#A1A1AA;text-align:center;">{{ $siteName }}</p>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" bgcolor="#0B0B0F" style="background:#0B0B0F;padding:0 0 22px 0;font-size:0;line-height:0;">
                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto;">
                                <tr>
                                    @foreach ($wave as $height)
                                        <td valign="bottom" style="padding:0 3px;font-size:0;line-height:0;">
                                            <div style="width:4px;height:{{ $height }}px;background:#7659FA;border-radius:2px;font-size:0;line-height:{{ $height }}px;">&nbsp;</div>
                                        </td>
                                    @endforeach
                                </tr>
                            </table>
                        </td>
                    </tr>
                    @foreach ($sections as $index => $section)
                        <tr>
                            <td dir="{{ $section['dir'] }}" align="{{ $section['align'] }}" style="padding:{{ $index === 0 ? '36px' : '8px' }} 36px 8px 36px;text-align:{{ $section['align'] }};font-family:{{ $section['font'] }};background:#ffffff;">
                                @if ($index > 0)
                                    <div style="height:1px;background:#ECEAF3;margin:8px 0 32px 0;"></div>
                                @endif
                                @if ($section['headline'] !== '')
                                    <h1 style="display:block;margin:0 0 16px 0;font-family:{{ $section['font'] }};font-size:32px;line-height:1.2;font-weight:600;letter-spacing:-0.03em;color:#0B0B0F;text-align:{{ $section['align'] }};">{{ $section['headline'] }}</h1>
                                @endif
                                @if ($section['greeting'] !== '')
                                    <p style="margin:0 0 14px 0;font-family:{{ $section['font'] }};font-size:16px;line-height:1.5;font-weight:600;color:#16161D;text-align:{{ $section['align'] }};">{{ $section['greeting'] }}</p>
                                @endif
                                @foreach ($section['paragraphs'] as $paragraph)
                                    <p style="margin:0 0 14px 0;font-family:{{ $section['font'] }};font-size:16px;line-height:1.7;color:#3F3F4A;text-align:{{ $section['align'] }};">{!! $paragraph !!}</p>
                                @endforeach
                                @if ($section['highlights'] !== [])
                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;margin:18px 0 4px 0;">
                                        <tr>
                                            <td align="{{ $section['align'] }}" style="text-align:{{ $section['align'] }};">
                                                <table role="presentation" dir="{{ $section['dir'] }}" cellpadding="0" cellspacing="0">
                                                    @foreach ($section['highlights'] as $highlight)
                                                        <tr>
                                                            <td width="16" valign="middle" style="width:16px;padding:6px 8px;">
                                                                <div style="width:8px;height:8px;border-radius:4px;background:#7659FA;font-size:0;line-height:0;">&nbsp;</div>
                                                            </td>
                                                            <td valign="middle" dir="{{ $section['dir'] }}" style="padding:6px 0;font-family:{{ $section['font'] }};font-size:15px;line-height:1.45;color:#16161D;text-align:{{ $section['align'] }};">{{ $highlight }}</td>
                                                        </tr>
                                                    @endforeach
                                                </table>
                                            </td>
                                        </tr>
                                    </table>
                                @endif
                                @if ($section['cta_label'] !== '' && $section['cta_url'] !== '')
                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;margin:16px 0 0 0;">
                                        <tr>
                                            <td align="center" style="text-align:center;">
                                                <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto;">
                                                    <tr>
                                                        <td align="center" bgcolor="#7659FA" style="border-radius:999px;background:#7659FA;">
                                                            <a href="{{ $section['cta_url'] }}" style="display:inline-block;padding:14px 26px;font-family:{{ $section['font'] }};font-size:15px;font-weight:600;line-height:1.2;color:#ffffff;text-decoration:none;border-radius:999px;">{{ $section['cta_label'] }}</a>
                                                        </td>
                                                    </tr>
                                                </table>
                                            </td>
                                        </tr>
                                    </table>
                                @endif
                                @if ($section['footer'] !== '')
                                    <p style="display:block;margin:8px 0 4px 0;font-family:{{ $section['font'] }};font-size:13px;line-height:1.5;color:#71717A;text-align:center;">{{ $section['footer'] }}</p>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    <tr>
                        <td align="center" bgcolor="#0B0B0F" style="background:#0B0B0F;padding:22px 32px 26px 32px;text-align:center;">
                            <p style="margin:0 0 8px 0;font-family:{{ $font }};font-size:12px;line-height:1.5;letter-spacing:0.14em;color:#A1A1AA;text-align:center;">{{ $siteName }}</p>
                            @if ($unsubscribeUrlEn && $unsubscribeUrlAr)
                                <p style="margin:0;font-family:{{ $font }};font-size:12px;line-height:1.6;color:#C4B8FF;text-align:center;">
                                    <a href="{{ $unsubscribeUrlEn }}" style="color:#C4B8FF;text-decoration:underline;">Unsubscribe</a>
                                    <span style="color:#5C5C6A;"> · </span>
                                    <a href="{{ $unsubscribeUrlAr }}" dir="rtl" style="color:#C4B8FF;text-decoration:underline;font-family:{{ $fontAr }};">إلغاء الاشتراك</a>
                                </p>
                            @elseif ($preview)
                                <p style="margin:0;font-family:{{ $font }};font-size:12px;line-height:1.6;color:#C4B8FF;text-align:center;">Unsubscribe · إلغاء الاشتراك</p>
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
