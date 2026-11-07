<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $firm['firm.name'] }}</title>
</head>
<body style="margin:0;padding:24px;background:#f3f4f7;font-family:Arial,Helvetica,sans-serif;color:#161a2b;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;background:#ffffff;border:1px solid #d9dce6;border-radius:6px;">
        <tr>
            <td style="padding:20px 24px;border-bottom:1px solid #d9dce6;">
                <strong style="font-size:18px;color:#2c3d8a;">{{ $firm['firm.name'] }}</strong>
                @if ($firm['firm.bar'])<br><span style="font-size:12px;color:#585f77;">{{ $firm['firm.bar'] }}</span>@endif
            </td>
        </tr>
        <tr>
            <td style="padding:24px;font-size:15px;line-height:1.55;">{!! nl2br(e($body)) !!}</td>
        </tr>
        <tr>
            <td style="padding:16px 24px;border-top:1px solid #d9dce6;font-size:12px;color:#585f77;">
                {{ $firm['firm.address'] }}@if ($firm['firm.phone']) · {{ $firm['firm.phone'] }}@endif
            </td>
        </tr>
    </table>
</body>
</html>
