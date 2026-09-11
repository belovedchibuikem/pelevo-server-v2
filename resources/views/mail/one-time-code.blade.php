<x-mail::message>
# Pelevo verification

Use this code to complete your {{ str_replace('_', ' ', $purpose) }} request:

<x-mail::panel>
{{ $code }}
</x-mail::panel>

This code expires in 10 minutes and can be attempted no more than five times. If you did not request it, ignore this message.
</x-mail::message>
