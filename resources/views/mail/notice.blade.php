@component('mail.layout', ['preheader' => $preheader, 'eyebrow' => $eyebrow, 'heading' => $heading, 'footerNote' => $footerNote ?? null])
<p style="margin:0 0 18px;font-size:16px;line-height:1.6;color:#334155;">{{ $intro }}</p>
@isset($detail)
    @if ($detail !== null && $detail !== '')
        <p style="margin:0 0 22px;font-size:15px;line-height:1.65;color:#475569;white-space:pre-wrap;">{{ $detail }}</p>
    @endif
@endisset
@if (! empty($actionUrl) && ! empty($actionLabel))
    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 8px;">
        <tr>
            <td style="background:#14b8a6;border-radius:10px;">
                <a href="{{ $actionUrl }}" style="display:inline-block;padding:14px 22px;font-size:15px;font-weight:700;color:#06201c;text-decoration:none;">{{ $actionLabel }}</a>
            </td>
        </tr>
    </table>
@endif
@endcomponent
