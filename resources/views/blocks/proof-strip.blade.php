{{--
    Metrics come from either the authored textarea (one per line) or the
    proof_metric collection. The collection wins when it has content, which is
    why this block declares a dependency on it.
--}}
@php
    $metrics = collect($items)->pluck('text')->filter()->values()->all();

    if ($metrics === [] && !empty($data['metrics'])) {
        $metrics = array_values(array_filter(array_map(
            'trim',
            preg_split('/\r\n|\r|\n/', trim($data['metrics']))
        )));
    }
@endphp

@if ($metrics !== [])
    <section class="block block-proof-strip theme-{{ $theme }}">
        <ul class="proof-metrics">
            @foreach ($metrics as $metric)
                <li>{{ $metric }}</li>
            @endforeach
        </ul>
    </section>
@endif
