{{-- Branded, responsive catalogue email (EmailRenderer). Table layout and inline styles for mail clients; RTL for Arabic. --}}
@php
    $start = $dir === 'rtl' ? 'right' : 'left';
    $end = $dir === 'rtl' ? 'left' : 'right';
    $font = $dir === 'rtl' ? "Tahoma, 'Segoe UI', Arial, sans-serif" : "-apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>{{ $subject }}</title>
<style>
    @media only screen and (max-width: 620px) {
        .nw-container { width: 100% !important; }
        .nw-pad { padding-left: 20px !important; padding-right: 20px !important; }
        .nw-button { display: block !important; width: 100% !important; }
    }
</style>
</head>
<body dir="{{ $dir }}" style="margin:0;padding:0;background:#f3f4f6;font-family:{{ $font }};color:#111827;direction:{{ $dir }};text-align:{{ $start }};">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">{{ $preheader }}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;">
<tr><td align="center" style="padding:24px 12px;">
<table role="presentation" class="nw-container" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;">
    <tr><td class="nw-pad" style="background:#feee00;padding:20px 32px;text-align:{{ $start }};">
        <span style="font-size:24px;font-weight:800;letter-spacing:-0.5px;color:#111827;">{{ $brand }}</span>
    </td></tr>
    <tr><td class="nw-pad" style="padding:32px 32px 8px;text-align:{{ $start }};">
        <h1 style="margin:0 0 16px;font-size:20px;line-height:28px;font-weight:700;color:#111827;">{{ $subject }}</h1>
        @foreach ($paragraphs as $paragraph)
            <p style="margin:0 0 14px;font-size:15px;line-height:24px;color:#374151;">{!! nl2br(e($paragraph)) !!}</p>
        @endforeach
    </td></tr>
    @foreach ($blocks as $block)
        <tr><td class="nw-pad" style="padding:8px 32px;text-align:{{ $start }};">
        @switch($block['type'])
            @case('code')
                <div style="margin:4px 0 8px;padding:16px;background:#f9fafb;border:1px dashed #d1d5db;border-radius:8px;text-align:center;font-size:30px;letter-spacing:8px;font-weight:700;color:#111827;" dir="ltr">{{ $block['value'] }}</div>
                @break
            @case('table')
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:14px;">
                    <tr>
                        @foreach ($block['columns'] as $i => $column)
                            <th style="padding:8px 4px;border-bottom:1px solid #e5e7eb;color:#6b7280;font-weight:600;text-align:{{ $i === 0 ? $start : $end }};">{{ $column }}</th>
                        @endforeach
                    </tr>
                    @foreach ($block['rows'] as $row)
                        <tr>
                            @foreach ($row as $i => $cell)
                                <td style="padding:8px 4px;border-bottom:1px solid #f3f4f6;color:#111827;text-align:{{ $i === 0 ? $start : $end }};">{{ $cell }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </table>
                @break
            @case('totals')
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;">
                    @foreach ($block['rows'] as $row)
                        @php $last = $loop->last; @endphp
                        <tr>
                            <td style="padding:4px;text-align:{{ $start }};color:{{ $last ? '#111827' : '#6b7280' }};font-weight:{{ $last ? '700' : '400' }};{{ $last ? 'border-top:1px solid #e5e7eb;padding-top:10px;font-size:16px;' : '' }}">{{ $row[0] }}</td>
                            <td style="padding:4px;text-align:{{ $end }};color:#111827;font-weight:{{ $last ? '700' : '400' }};{{ $last ? 'border-top:1px solid #e5e7eb;padding-top:10px;font-size:16px;' : '' }}">{{ $row[1] }}</td>
                        </tr>
                    @endforeach
                </table>
                @break
            @case('facts')
                @if ($block['title'])<p style="margin:8px 0 6px;font-size:13px;font-weight:700;color:#111827;text-transform:uppercase;letter-spacing:0.4px;">{{ $block['title'] }}</p>@endif
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;background:#f9fafb;border-radius:8px;">
                    @foreach ($block['rows'] as $row)
                        <tr>
                            <td style="padding:6px 12px;color:#6b7280;width:40%;text-align:{{ $start }};vertical-align:top;">{{ $row[0] }}</td>
                            <td style="padding:6px 12px;color:#111827;text-align:{{ $start }};">{!! nl2br(e($row[1])) !!}</td>
                        </tr>
                    @endforeach
                </table>
                @break
            @case('note')
                <p style="margin:4px 0;font-size:13px;line-height:20px;color:#6b7280;">{{ $block['text'] }}</p>
                @break
        @endswitch
        </td></tr>
    @endforeach
    @if ($button)
        <tr><td class="nw-pad" style="padding:16px 32px 8px;text-align:{{ $start }};">
            <a href="{{ $button['url'] }}" class="nw-button" style="display:inline-block;background:#3866df;color:#ffffff;text-decoration:none;font-weight:600;font-size:15px;padding:12px 24px;border-radius:8px;text-align:center;">{{ $button['label'] }}</a>
        </td></tr>
    @endif
    <tr><td class="nw-pad" style="padding:24px 32px 28px;">
        <hr style="border:none;border-top:1px solid #e5e7eb;margin:0 0 16px;">
        <p style="margin:0 0 8px;font-size:12px;line-height:18px;color:#6b7280;">
            <a href="{{ $footer['help'] }}" style="color:#3866df;text-decoration:none;">{{ __('Help centre') }}</a>
            &nbsp;·&nbsp;<a href="{{ $footer['terms'] }}" style="color:#3866df;text-decoration:none;">{{ __('Terms') }}</a>
            &nbsp;·&nbsp;<a href="{{ $footer['privacy'] }}" style="color:#3866df;text-decoration:none;">{{ __('Privacy') }}</a>
            @if ($footer['preferences'])&nbsp;·&nbsp;<a href="{{ $footer['preferences'] }}" style="color:#3866df;text-decoration:none;">{{ __('Email preferences') }}</a>@endif
        </p>
        <p style="margin:0 0 8px;font-size:12px;line-height:18px;color:#9ca3af;">{{ $footer['reason'] }}
            @if ($footer['unsubscribe'])<a href="{{ $footer['unsubscribe'] }}" style="color:#6b7280;">{{ __('Unsubscribe') }}</a>@endif
        </p>
        <p style="margin:0;font-size:12px;line-height:18px;color:#9ca3af;">© {{ date('Y') }} {{ $brand }}@if ($footer['country']) · {{ $footer['country'] }}@endif</p>
    </td></tr>
</table>
</td></tr>
</table>
</body>
</html>
