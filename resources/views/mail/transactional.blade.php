<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $contentData['subject'] }}</title>
</head>
<body style="margin:0;background:#f1f5f6;color:#183445;font-family:Arial,sans-serif">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:28px 12px;background:#f1f5f6">
        <tr><td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:#fff;border:1px solid #dce6e9;border-radius:12px">
                <tr><td style="padding:24px 28px;border-bottom:1px solid #e5edef">
                    @if(!empty($branding['logo_url']) && filter_var($branding['logo_url'], FILTER_VALIDATE_URL))
                        <img src="{{ $branding['logo_url'] }}" alt="{{ $branding['name'] }}" style="max-width:180px;max-height:54px">
                    @else
                        <strong style="font-size:18px;color:{{ $branding['primary_color'] }}">{{ $branding['name'] }}</strong>
                    @endif
                </td></tr>
                <tr><td style="padding:30px 28px">
                    <p style="margin:0 0 12px;color:#526c7a">{{ $contentData['greeting'] ?? $branding['greeting'] }}</p>
                    <h1 style="margin:0 0 18px;font-size:23px">{{ $contentData['heading'] ?? $contentData['subject'] }}</h1>
                    <p style="margin:0;line-height:1.65;white-space:pre-line">{{ $contentData['message'] }}</p>
                    @if(!empty($contentData['action_url']) && !empty($contentData['action_label']))
                        <p style="margin:26px 0"><a href="{{ $contentData['action_url'] }}" style="display:inline-block;padding:12px 18px;border-radius:8px;background:{{ $branding['primary_color'] }};color:#fff;text-decoration:none">{{ $contentData['action_label'] }}</a></p>
                    @endif
                </td></tr>
                <tr><td style="padding:18px 28px;border-top:1px solid #e5edef;color:#607783;font-size:13px;line-height:1.5">{{ $branding['footer'] }}</td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>
