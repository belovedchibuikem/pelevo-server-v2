@component('mail.layout', ['preheader' => $preheader, 'eyebrow' => $eyebrow, 'heading' => $heading, 'footerNote' => $footerNote ?? null])
<p style="margin:0 0 22px;font-size:16px;line-height:1.6;color:#334155;">Here is what happened on Pelevo since your last summary ({{ $when }}).</p>
@foreach ($items as $item)
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 14px;border:1px solid #d7e2de;border-radius:12px;">
        <tr>
            <td style="padding:16px 18px;">
                <p style="margin:0 0 6px;font-size:15px;font-weight:700;color:#06201c;">{{ $item['title'] }}</p>
                <p style="margin:0;font-size:14px;line-height:1.55;color:#475569;">{{ $item['body'] }}</p>
            </td>
        </tr>
    </table>
@endforeach
@endcomponent
