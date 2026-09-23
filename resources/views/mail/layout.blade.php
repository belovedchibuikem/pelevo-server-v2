<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <title>{{ $heading ?? 'Pelevo' }}</title>
    @isset($preheader)
        <span style="display:none!important;visibility:hidden;opacity:0;color:transparent;height:0;width:0;overflow:hidden">{{ $preheader }}</span>
    @endisset
</head>
<body style="margin:0;padding:0;background:#e8eeec;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#e8eeec;padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #d7e2de;">
                <tr>
                    <td style="background:#06201c;padding:28px 32px 24px;">
                        <p style="margin:0 0 8px;font-size:11px;letter-spacing:.28em;font-weight:700;color:#14b8a6;">PELEVO</p>
                        <p style="margin:0;font-size:13px;color:#9ad7ce;">Nigerian podcasts, finally home.</p>
                    </td>
                </tr>
                <tr>
                    <td style="height:4px;background:#14b8a6;font-size:0;line-height:0;">&nbsp;</td>
                </tr>
                <tr>
                    <td style="padding:36px 32px 28px;color:#0f172a;">
                        @isset($eyebrow)
                            <p style="margin:0 0 10px;font-size:11px;letter-spacing:.18em;text-transform:uppercase;font-weight:700;color:#0f766e;">{{ $eyebrow }}</p>
                        @endisset
                        @isset($heading)
                            <h1 style="margin:0 0 18px;font-size:26px;line-height:1.25;font-weight:700;color:#06201c;">{{ $heading }}</h1>
                        @endisset
                        {!! $slot !!}
                    </td>
                </tr>
                <tr>
                    <td style="padding:0 32px 32px;">
                        <p style="margin:0;padding-top:20px;border-top:1px solid #e2e8f0;font-size:12px;line-height:1.6;color:#64748b;">
                            Sent by Pelevo · {{ config('mail.from.address') }}<br>
                            {{ $footerNote ?? 'This is a transactional message. If you were not expecting it, you can ignore it.' }}
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
