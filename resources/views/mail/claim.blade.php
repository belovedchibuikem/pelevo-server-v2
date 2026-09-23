@component('mail.layout', ['preheader' => 'Use this code to finish claiming '.$showTitle.' on Pelevo.', 'eyebrow' => 'Show claim', 'heading' => 'Verify this show is yours'])
@if ($artworkUrl)
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 20px;">
    <tr>
        <td style="padding:0 16px 0 0;vertical-align:top;">
            <img src="{{ $artworkUrl }}" alt="" width="72" height="72" style="display:block;border-radius:10px;border:1px solid #d7e2de;">
        </td>
        <td style="vertical-align:middle;">
            <p style="margin:0 0 4px;font-size:16px;font-weight:700;color:#06201c;">{{ $showTitle }}</p>
            @if ($author)
                <p style="margin:0;font-size:13px;color:#64748b;">{{ $author }}</p>
            @endif
        </td>
    </tr>
</table>
@else
<p style="margin:0 0 18px;font-size:16px;line-height:1.6;color:#334155;">Someone is claiming <strong>{{ $showTitle }}</strong>{{ $author ? ' by '.$author : '' }} on Pelevo. Use this code only if you started that claim.</p>
@endif
<p style="margin:0 0 18px;font-size:16px;line-height:1.6;color:#334155;">Enter this verification code in Pelevo to prove you control the RSS owner email for this show.</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 22px;">
    <tr>
        <td align="center" style="background:#f0fdfa;border:1px solid #99f6e4;border-radius:12px;padding:22px 16px;">
            <p style="margin:0;font-size:34px;letter-spacing:.42em;font-weight:700;color:#0f766e;font-family:Consolas,Monaco,monospace;">{{ $code }}</p>
        </td>
    </tr>
</table>
<p style="margin:0;font-size:13px;line-height:1.6;color:#94a3b8;">The code expires shortly and can only be used for this claim. If you did not request it, ignore this email — your show stays unclaimed.</p>
@endcomponent
