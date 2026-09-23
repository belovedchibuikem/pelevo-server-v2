@component('mail.layout', ['preheader' => 'Reset your Pelevo administrator password. This link expires in 30 minutes.', 'eyebrow' => 'Operations', 'heading' => 'Reset your administrator password'])
<p style="margin:0 0 20px;font-size:16px;line-height:1.6;color:#334155;">A password reset was requested for your Pelevo operations account. The link below works once and expires in 30 minutes.</p>
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 22px;">
    <tr>
        <td>
            <a href="{{ $resetUrl }}" style="display:inline-block;background:#14b8a6;color:#06201c;text-decoration:none;font-weight:700;font-size:14px;padding:14px 22px;border-radius:10px;">Reset administrator password</a>
        </td>
    </tr>
</table>
<p style="margin:0;font-size:13px;line-height:1.6;color:#94a3b8;">If you did not ask for this, contact a superadministrator immediately. Do not forward this email.</p>
@endcomponent
