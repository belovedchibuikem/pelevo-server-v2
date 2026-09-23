@component('mail.layout', ['preheader' => $preheader, 'eyebrow' => $eyebrow, 'heading' => $heading])
<p style="margin:0 0 20px;font-size:16px;line-height:1.6;color:#334155;">{{ $intro }}</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 22px;">
    <tr>
        <td align="center" style="background:#f0fdfa;border:1px solid #99f6e4;border-radius:12px;padding:22px 16px;">
            <p style="margin:0;font-size:34px;letter-spacing:.42em;font-weight:700;color:#0f766e;font-family:Consolas,Monaco,monospace;">{{ $code }}</p>
        </td>
    </tr>
</table>
<p style="margin:0 0 10px;font-size:14px;line-height:1.6;color:#475569;">{{ $hint }}</p>
<p style="margin:0;font-size:13px;line-height:1.6;color:#94a3b8;">This code expires in 10 minutes and can be tried at most five times. Pelevo will never ask you to share it.</p>
@endcomponent
