@php
    $arabic = ($locale ?? 'en') === 'ar';
@endphp
@if(\App\Support\SiteDesign::isNew())
@include('components.design-new.unsubscribed')
@else
<!DOCTYPE html>
<html lang="{{ $arabic ? 'ar' : 'en' }}" dir="{{ $arabic ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $arabic ? 'تم إلغاء الاشتراك' : 'Unsubscribed' }}</title>
    @include('components.site-fonts')
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            background: #F3F1FA;
            color: #16161D;
            font-family: 'IBM Plex Sans', sans-serif;
        }
        html[dir="rtl"] body {
            font-family: 'IBM Plex Sans', 'IBM Plex Sans Arabic', sans-serif;
        }
        .wrap {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px 16px;
        }
        .card {
            width: 100%;
            max-width: 480px;
            background: #fff;
            border: 1px solid #E4E0F2;
            border-radius: 24px;
            box-shadow: 0 24px 60px rgba(22, 22, 29, 0.06);
            overflow: hidden;
            text-align: center;
        }
        .bar { height: 4px; background: #7659FA; }
        .inner { padding: 44px 40px 40px; }
        .logo { height: 48px; width: auto; display: block; margin: 0 auto; }
        .site {
            margin: 16px 0 0;
            font-family: 'IBM Plex Sans', sans-serif;
            font-size: 13px;
            line-height: 1.4;
            letter-spacing: 0.14em;
            color: #6E6A80;
        }
        .rule { height: 1px; background: #E8E5F3; margin: 28px auto; max-width: 180px; }
        .email {
            display: inline-block;
            margin: 0 0 22px;
            padding: 8px 14px;
            background: #F6F4FF;
            border-radius: 999px;
            font-family: 'IBM Plex Sans', sans-serif;
            font-size: 14px;
            line-height: 1.4;
            color: #3F3F4A;
            direction: ltr;
        }
        h1 {
            font-size: 28px;
            line-height: 1.35;
            font-weight: 600;
            letter-spacing: -0.02em;
            margin: 0 0 10px;
        }
        p { margin: 0 auto; max-width: 34em; font-size: 16px; line-height: 1.7; color: #4A4A55; }
        .home {
            display: inline-block;
            margin-top: 28px;
            color: #5B45C6;
            font-weight: 600;
            font-size: 14px;
            letter-spacing: 0.04em;
            text-decoration: none;
            font-family: 'IBM Plex Sans', sans-serif;
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <div class="bar"></div>
            <div class="inner">
                @if ($arabic)
                    <img class="logo" src="{{ asset('images/logo-ar-light.svg') }}" alt="منصة إسمع">
                @else
                    <img class="logo" src="{{ asset('images/logo-light.svg') }}" alt="Listen">
                @endif
                <p class="site">listenai.studio</p>
                <div class="rule"></div>
                <div class="email">{{ $email }}</div>
                @if ($arabic)
                    <h1>تم إلغاء الاشتراك</h1>
                    <p>لن تصلك بعد الآن رسائل منصة إسمع الإخبارية.</p>
                @else
                    <h1>You are unsubscribed</h1>
                    <p>This address will no longer receive Listen newsletters.</p>
                @endif
                <a class="home" href="{{ url('/') }}">listenai.studio</a>
            </div>
        </div>
    </div>
</body>
</html>
@endif
