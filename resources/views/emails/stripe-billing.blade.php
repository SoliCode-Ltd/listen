<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subject }}</title>
    <style>
        /* Base Resets */
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; }
        img { border: 0; outline: none; text-decoration: none; display: block; }
        table { border-collapse: collapse !important; }
        body { margin: 0 !important; padding: 0 !important; width: 100% !important; font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background-color: #f4f5f7; color: #1f2937; }
        
        /* Container Setup */
        .wrapper { width: 100%; table-layout: fixed; background-color: #f4f5f7; padding: 40px 0; }
        .main { max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 16px; box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05); overflow: hidden; }
        
        /* Header */
        .header { background: linear-gradient(135deg, #FF4433 0%, #E63522 100%); padding: 40px 30px; text-align: center; }
        .header-logo { font-family: 'Outfit', -apple-system, sans-serif; font-size: 32px; font-weight: 800; color: #ffffff; letter-spacing: -0.5px; margin: 0; text-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .header-subtitle { color: rgba(255, 255, 255, 0.85); font-size: 14px; font-weight: 500; margin-top: 5px; text-transform: uppercase; letter-spacing: 1px; }
        
        /* Content */
        .content { padding: 40px 35px; text-align: left; }
        .greeting { font-size: 22px; font-weight: 700; margin-bottom: 24px; color: #111827; }
        .section { margin-bottom: 24px; line-height: 1.6; font-size: 16px; color: #4b5563; }
        .section p { margin-top: 0; margin-bottom: 12px; }
        .section p:last-child { margin-bottom: 0; }
        
        /* Details Box */
        .details-wrapper { background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 12px; padding: 24px; margin: 32px 0; }
        .details-title { font-weight: 700; font-size: 13px; text-transform: uppercase; letter-spacing: 1.2px; color: #6b7280; margin-bottom: 16px; border-bottom: 1px solid #e5e7eb; padding-bottom: 12px; }
        .details-table { width: 100%; border-collapse: collapse; }
        .details-table td { padding: 10px 0; font-size: 15px; border-bottom: 1px solid #f3f4f6; }
        .details-table tr:last-child td { border-bottom: none; padding-bottom: 0; }
        .details-table .d-label { color: #6b7280; width: 45%; vertical-align: top; }
        .details-table .d-value { color: #111827; font-weight: 600; text-align: right; word-break: break-word; vertical-align: top; }
        
        /* Action Button */
        .action-area { text-align: center; margin: 40px 0 20px 0; }
        .action-button { display: inline-block; background-color: #FF4433; color: #ffffff; text-decoration: none; padding: 14px 32px; border-radius: 9999px; font-weight: 600; font-size: 16px; letter-spacing: 0.3px; transition: all 0.2s ease; box-shadow: 0 4px 12px rgba(255, 68, 51, 0.25); text-align: center; }
        .action-button:hover { background-color: #e63522; box-shadow: 0 6px 16px rgba(255, 68, 51, 0.35); transform: translateY(-1px); }
        .action-button span { color: #ffffff !important; text-decoration: none !important; }

        /* Signoff */
        .signoff { margin-top: 40px; font-size: 16px; color: #4b5563; line-height: 1.6; }
        .signoff strong { color: #111827; font-weight: 600; }
        
        /* Footer */
        .footer { background-color: #f9fafb; padding: 32px 35px; text-align: center; border-top: 1px solid #f3f4f6; }
        .footer p { margin: 6px 0; font-size: 13px; color: #9ca3af; line-height: 1.5; }
        .footer-brand { font-family: 'Outfit', -apple-system, sans-serif; font-weight: 700; color: #d1d5db; font-size: 20px; margin-top: 20px; letter-spacing: 0.5px; }

        /* Mobile Adjustments */
        @media screen and (max-width: 600px) {
            .wrapper { padding: 20px 15px; }
            .header { padding: 30px 20px; }
            .content { padding: 30px 20px; }
            .details-wrapper { padding: 18px; margin: 24px 0; }
            .details-table .d-label { width: 40%; font-size: 14px; }
            .details-table .d-value { font-size: 14px; }
            .action-button { display: block; width: 100%; padding: 16px 0; box-sizing: border-box; }
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="main">
            <!-- Header -->
            <div class="header">
                <div class="header-logo">LISTEN</div>
                <div class="header-subtitle">Billing Update</div>
            </div>
            
            <!-- Content -->
            <div class="content">
                <div class="greeting">Hi {{ $user->name ?: 'there' }},</div>
                
                <div class="section">
                    @foreach($summaryLines as $line)
                        <p>{{ $line }}</p>
                    @endforeach
                </div>

                @if(!empty($details))
                <div class="details-wrapper">
                    <div class="details-title">Transaction Details</div>
                    <table class="details-table" role="presentation">
                        @foreach($details as $label => $value)
                        <tr>
                            <td class="d-label">{{ $label }}</td>
                            <td class="d-value">{{ $value }}</td>
                        </tr>
                        @endforeach
                    </table>
                </div>
                @endif

                @if(!empty($actionUrl))
                <div class="action-area">
                    <a href="{{ $actionUrl }}" class="action-button">
                        <span style="color:#ffffff;">{{ $actionText ?? 'Manage Billing' }}</span>
                    </a>
                </div>
                @endif

                <div class="signoff">
                    Thanks,<br>
                    <strong>The Listen Studio Team</strong>
                </div>
            </div>
            
            <!-- Footer -->
            <div class="footer">
                <p>&copy; {{ date('Y') }} Listen Studio. All rights reserved.</p>
                <p>You received this email because of an important update regarding your account or billing.</p>
                @if(isset($actionUrl) && empty($actionText))
                <p style="margin-top: 15px; word-break: break-all;">
                    <a href="{{ $actionUrl }}" style="color: #6b7280; text-decoration: underline;">{{ $actionUrl }}</a>
                </p>
                @endif
                <div class="footer-brand">LISTEN</div>
            </div>
        </div>
    </div>
</body>
</html>
