<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Unsubscribed · تم إلغاء الاشتراك</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Sans+Arabic:wght@400;500;600&display=swap">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            background: #F3F1FA;
            color: #16161D;
            font-family: "IBM Plex Sans", "Segoe UI", Arial, sans-serif;
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
            max-width: 520px;
            background: #fff;
            border: 1px solid #E4E0F2;
            border-radius: 24px;
            box-shadow: 0 24px 60px rgba(22, 22, 29, 0.06);
            overflow: hidden;
        }
        .bar { height: 4px; background: #7659FA; }
        .inner { padding: 36px 36px 32px; }
        .logos { display: flex; flex-direction: column; align-items: flex-start; gap: 8px; margin: 0 0 28px; }
        .logos img { height: 36px; width: auto; display: block; }
        .email {
            display: inline-block;
            margin: 0 0 22px;
            padding: 8px 12px;
            background: #F6F4FF;
            border-radius: 999px;
            font-size: 14px;
            line-height: 1.4;
            color: #3F3F4A;
        }
        h1 {
            font-size: 26px;
            line-height: 1.3;
            font-weight: 600;
            letter-spacing: -0.02em;
            margin: 0 0 8px;
        }
        p { margin: 0; font-size: 15px; line-height: 1.7; color: #4A4A55; }
        .rule { height: 1px; background: #E8E5F3; margin: 24px 0; }
        .ar {
            direction: rtl;
            text-align: right;
            font-family: "IBM Plex Sans Arabic", Tahoma, Arial, sans-serif;
        }
        .home {
            display: inline-block;
            margin-top: 28px;
            color: #5B45C6;
            font-weight: 600;
            font-size: 14px;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <div class="bar"></div>
            <div class="inner">
                <div class="logos">
                    <img src="{{ asset('images/logo-light.svg') }}" alt="Listen">
                    <img src="{{ asset('images/logo-ar-light.svg') }}" alt="منصة إسمع">
                </div>
                <div class="email">{{ $email }}</div>
                <h1>You are unsubscribed</h1>
                <p>This address will no longer receive Listen newsletters.</p>
                <div class="rule"></div>
                <div class="ar">
                    <h1>تم إلغاء الاشتراك</h1>
                    <p>لن تصلك بعد الآن رسائل منصة إسمع الإخبارية.</p>
                </div>
                <a class="home" href="{{ url('/') }}">listenai.studio</a>
            </div>
        </div>
    </div>
</body>
</html>
