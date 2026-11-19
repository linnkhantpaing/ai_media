@php
    $logo = isset($message) && is_object($message) && method_exists($message, 'embed')
        ? $message->embed($logoPath)
        : 'data:image/png;base64,'.base64_encode((string) file_get_contents($logoPath));
    $font = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue','Noto Sans Myanmar','Myanmar Text','Padauk','Noto Sans Thai','Leelawadee UI',Tahoma,Arial,sans-serif";
    $lh = $locale === 'en' ? '1.7' : '1.9';
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="color-scheme" content="dark">
<meta name="supported-color-schemes" content="dark">
<title>{{ $title }}</title>
<style>
    body { margin:0; padding:0; background:#07060F; }
    a { text-decoration:none; }
    @media (max-width:600px) {
        .zt-shell { padding:24px 12px !important; }
        .zt-card-body { padding:30px 22px 28px !important; }
        .zt-title { font-size:23px !important; }
        .zt-button a { display:block !important; }
    }
</style>
</head>
<body style="margin:0;padding:0;background:#07060F;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#07060F;font-size:1px;line-height:1px;">{{ $preheader }}&#8203;&nbsp;&#8203;&nbsp;&#8203;&nbsp;&#8203;&nbsp;&#8203;&nbsp;&#8203;&nbsp;&#8203;&nbsp;&#8203;&nbsp;</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#07060F" style="background:#07060F;background-image:radial-gradient(ellipse at 50% 0%,#2A1A5E 0%,#07060F 62%);">
<tr><td align="center" class="zt-shell" style="padding:40px 16px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;">

    <tr><td align="center" style="padding:0 0 26px;">
        <img src="{{ $logo }}" width="196" height="65" alt="{{ $brand['name'] }}" style="display:block;width:196px;max-width:60%;height:auto;border:0;outline:none;">
    </td></tr>

    <tr><td style="border-radius:22px;background:#13111F;border:1px solid #2A2645;overflow:hidden;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr><td height="3" style="height:3px;line-height:3px;font-size:0;border-radius:22px 22px 0 0;background:#8B5CF6;background-image:linear-gradient(90deg,#6631C4,#B8A8FF 55%,#72E2C3);">&nbsp;</td></tr>
            <tr><td class="zt-card-body" style="padding:38px 40px 34px;font-family:{!! $font !!};">

                <div style="font-size:11px;line-height:1;letter-spacing:1.8px;text-transform:uppercase;font-weight:700;color:#72E2C3;">{{ $eyebrow }}</div>
                <h1 class="zt-title" style="margin:14px 0 22px;font-size:27px;line-height:1.3;font-weight:700;color:#FFFFFF;letter-spacing:-0.3px;">{{ $title }}</h1>

                <p style="margin:0 0 10px;font-size:15px;line-height:{{ $lh }};font-weight:600;color:#EDEBFA;">{{ $hello }}</p>
                <p style="margin:0;font-size:14px;line-height:{{ $lh }};color:#A9A6C2;">{{ $intro }}</p>

                @if ($features)
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0 0;">
                    @foreach ($features as $feature)
                    <tr><td style="padding:5px 0;font-size:14px;line-height:{{ $lh }};color:#EDEBFA;">
                        <span style="display:inline-block;width:7px;height:7px;margin:0 12px 1px 2px;border-radius:2px;background:#B8A8FF;transform:rotate(45deg);vertical-align:middle;"></span>{{ $feature }}
                    </td></tr>
                    @endforeach
                </table>
                @endif

                @if ($private)
                <p style="margin:22px 0 0;font-size:14px;line-height:{{ $lh }};color:#A9A6C2;">{{ $private }}</p>
                @endif

                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:26px 0 0;border-radius:14px;background:#0C0B16;border:1px solid #221F38;">
                    @foreach ($rows as $index => [$label, $value])
                    <tr>
                        <td style="padding:13px 18px;{{ $index ? 'border-top:1px solid #1D1A31;' : '' }}font-size:12px;line-height:1.6;color:#7E7A99;white-space:nowrap;vertical-align:top;">{{ $label }}</td>
                        <td align="right" style="padding:13px 18px;{{ $index ? 'border-top:1px solid #1D1A31;' : '' }}font-size:13px;line-height:1.6;font-weight:600;color:#FFFFFF;word-break:break-all;">{{ $value }}</td>
                    </tr>
                    @endforeach
                </table>

                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:30px 0 0;">
                    <tr><td align="center" class="zt-button">
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto;">
                            <tr><td align="center" bgcolor="#8B5CF6" style="border-radius:12px;background:#8B5CF6;background-image:linear-gradient(110deg,#A15DE5,#8B5CF6 50%,#6631C4);box-shadow:0 8px 24px rgba(139,92,246,.35);">
                                <a href="{{ $url }}" target="_blank" rel="noopener" style="display:inline-block;padding:15px 38px;font-family:{!! $font !!};font-size:15px;line-height:1.4;font-weight:700;color:#FFFFFF;text-decoration:none;border-radius:12px;">{{ $action }}&nbsp;&nbsp;&rarr;</a>
                            </td></tr>
                        </table>
                    </td></tr>
                </table>

                @if ($after)
                <p style="margin:18px 0 0;font-size:12px;line-height:{{ $lh }};color:#7E7A99;text-align:center;">{{ $after }}</p>
                @endif

                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:28px 0 0;border-radius:14px;background:#0F1A1C;border:1px solid #1C3533;">
                    <tr><td style="padding:16px 18px;">
                        <div style="font-size:13px;line-height:1.6;font-weight:700;color:#72E2C3;">{{ $noteTitle }}</div>
                        <div style="margin-top:4px;font-size:13px;line-height:{{ $lh }};color:#A9A6C2;">{{ $note }}</div>
                    </td></tr>
                </table>

                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:28px 0 0;">
                    <tr><td style="border-top:1px solid #221F38;padding-top:20px;font-size:12px;line-height:1.7;color:#7E7A99;">
                        {{ $fallback }}<br>
                        <a href="{{ $url }}" target="_blank" rel="noopener" style="color:#B8A8FF;text-decoration:underline;word-break:break-all;">{{ $url }}</a>
                    </td></tr>
                </table>

            </td></tr>
        </table>
    </td></tr>

    <tr><td align="center" style="padding:26px 12px 0;font-family:{!! $font !!};">
        <div style="font-size:13px;font-weight:700;letter-spacing:2px;color:#D9D4F5;">{{ strtoupper($brand['name']) }}</div>
        @if ($brand['tagline'])
        <div style="margin-top:4px;font-size:12px;color:#7E7A99;">{{ $brand['tagline'] }}</div>
        @endif
        @if ($support)
        <div style="margin-top:14px;font-size:12px;line-height:1.7;color:#7E7A99;">
            @if ($supportHref)<a href="{{ $supportHref }}" style="color:#B8A8FF;text-decoration:none;">{{ $support }}</a>@else{{ $support }}@endif
        </div>
        @endif
        <div style="margin-top:14px;font-size:11px;line-height:1.7;color:#5E5A78;">{{ $automated }}</div>
        <div style="margin-top:6px;font-size:11px;line-height:1.7;color:#5E5A78;">{{ $rights }}</div>
    </td></tr>

</table>
</td></tr>
</table>
</body>
</html>
