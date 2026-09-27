<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Unsubscribed · تم إلغاء الاشتراك</title>
    <style>
        body { margin: 0; background: #F3F1FA; color: #16161D; font-family: "Segoe UI", Arial, sans-serif; }
        .card { max-width: 520px; margin: 48px auto; background: #fff; border: 1px solid #E6E4F2; border-radius: 18px; padding: 32px; }
        h1 { font-size: 28px; line-height: 1.3; margin: 0 0 12px; }
        p { font-size: 16px; line-height: 1.6; color: #3F3F4A; }
        .ar { direction: rtl; text-align: right; font-family: Tahoma, Arial, sans-serif; }
        .mark { width: 36px; height: 36px; border-radius: 10px; background: #7659FA; color: #fff; display: inline-flex; align-items: center; justify-content: center; font-weight: 600; }
    </style>
</head>
<body>
    <div class="card">
        <div class="mark">L</div>
        <h1>You are unsubscribed</h1>
        <p>{{ $email }} will no longer receive Listen newsletters.</p>
        <h1 class="ar">تم إلغاء الاشتراك</h1>
        <p class="ar">لن تصلك بعد الآن رسائل Listen الإخبارية على {{ $email }}.</p>
    </div>
</body>
</html>
