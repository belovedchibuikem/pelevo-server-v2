<x-mail::message>
# Verify ownership of {{ $showTitle }}

Use this verification code in Pelevo: **{{ $code }}**

The code expires shortly and can only be used for this show claim. If you did not request it, ignore this message.

Thanks,<br>{{ config('app.name') }}
</x-mail::message>
