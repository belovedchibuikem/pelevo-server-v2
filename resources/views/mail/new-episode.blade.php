@component('mail.layout', ['preheader' => $preheader, 'eyebrow' => 'New episode', 'heading' => $heading, 'footerNote' => 'You received this because you follow '.$showTitle.' in Pelevo and have new episode alerts on.'])
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 22px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;">
    <tr>
        <td style="padding:16px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                    @if ($artworkUrl)
                    <td width="96" valign="top" style="padding:0 16px 0 0;">
                        <img src="{{ $artworkUrl }}" alt="" width="96" height="96" style="display:block;width:96px;height:96px;border-radius:12px;border:1px solid #d7e2de;object-fit:cover;">
                    </td>
                    @endif
                    <td valign="middle">
                        <p style="margin:0 0 6px;font-size:12px;letter-spacing:.08em;text-transform:uppercase;font-weight:700;color:#0f766e;">{{ $showTitle }}</p>
                        <p style="margin:0 0 8px;font-size:18px;line-height:1.35;font-weight:700;color:#06201c;">{{ $episodeTitle }}</p>
                        <p style="margin:0;font-size:13px;color:#64748b;">
                            {{ $author ?? 'Pelevo' }}
                            @if ($duration)
                                · {{ $duration }}
                            @endif
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
@if ($description)
<p style="margin:0 0 22px;font-size:15px;line-height:1.65;color:#475569;">{{ $description }}</p>
@endif
<table role="presentation" cellpadding="0" cellspacing="0">
    <tr>
        <td>
            <a href="{{ $listenUrl }}" style="display:inline-block;background:#14b8a6;color:#06201c;text-decoration:none;font-weight:700;font-size:14px;padding:14px 22px;border-radius:10px;">Listen in Pelevo</a>
        </td>
    </tr>
</table>
@endcomponent
