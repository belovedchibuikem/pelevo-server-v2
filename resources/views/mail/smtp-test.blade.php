@component('mail.layout', ['preheader' => 'Pelevo accepted a live SMTP test from your operations console.', 'eyebrow' => 'Mail setup', 'heading' => 'Your Pelevo mail connection is working'])
<p style="margin:0 0 18px;font-size:16px;line-height:1.6;color:#334155;">This is the branded test message from the Pelevo operations console. If you can read this, the saved SMTP host, port, username, and password were accepted.</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 8px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;">
    <tr>
        <td style="padding:16px 18px;">
            <p style="margin:0 0 8px;font-size:13px;color:#64748b;">Host</p>
            <p style="margin:0 0 14px;font-size:15px;font-weight:700;color:#06201c;">{{ $host }}:{{ $port }}</p>
            <p style="margin:0 0 8px;font-size:13px;color:#64748b;">Sent from</p>
            <p style="margin:0 0 14px;font-size:15px;font-weight:700;color:#06201c;">{{ $fromAddress }}</p>
            <p style="margin:0 0 8px;font-size:13px;color:#64748b;">Sent at</p>
            <p style="margin:0;font-size:15px;font-weight:700;color:#06201c;">{{ $sentAt }}</p>
        </td>
    </tr>
</table>
<p style="margin:16px 0 0;font-size:13px;line-height:1.6;color:#94a3b8;">Password resets, sign-in codes, show claims, and episode alerts now use this same connection.</p>
@endcomponent
