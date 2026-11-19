{!! strtoupper($brand['name']) !!} — {!! $eyebrow !!}

{!! $title !!}

{!! $hello !!}

{!! $intro !!}
@if ($features)

@foreach ($features as $feature)
- {!! $feature !!}
@endforeach
@endif
@if ($private)

{!! $private !!}
@endif

@foreach ($rows as [$label, $value])
{!! $label !!}: {!! $value !!}
@endforeach

{!! $action !!}:
{!! $url !!}
@if ($after)

{!! $after !!}
@endif

{!! $noteTitle !!}
{!! $note !!}
@if ($support)

{!! $support !!}
@endif

{!! $automated !!}
{!! $rights !!}
