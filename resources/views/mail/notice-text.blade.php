PELEVO
{{ $heading }}

{{ $intro }}
@if (! empty($detail))

{{ $detail }}
@endif
@if (! empty($actionUrl) && ! empty($actionLabel))

{{ $actionLabel }}: {{ $actionUrl }}
@endif
