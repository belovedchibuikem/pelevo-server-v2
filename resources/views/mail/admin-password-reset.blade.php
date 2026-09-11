<x-mail::message>
# Administrator password reset

A password reset was requested for your Pelevo administrator account. This link expires in 30 minutes and can be used once.

<x-mail::button :url="route('admin.password.reset', ['token' => $token])">
Reset administrator password
</x-mail::button>

If you did not request this, contact a superadministrator immediately.
</x-mail::message>
