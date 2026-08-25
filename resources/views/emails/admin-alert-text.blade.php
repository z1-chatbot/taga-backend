Taga

{{ $heading }}

{{ $intro }}
@if (! empty($rows))

@foreach ($rows as $label => $value)
{{ $label }}: {{ trim(html_entity_decode(strip_tags($value))) }}
@endforeach
@endif
@if ($note)

{{ $note }}
@endif
@if ($actionUrl && $actionLabel)

{{ $actionLabel }}: {{ $actionUrl }}
@endif

Sent to you because you administer this platform.
