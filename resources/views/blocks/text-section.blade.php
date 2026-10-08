<section class="block block-text theme-{{ $theme }}">
    @if (!empty($data['label']))
        <p class="block-label">{{ $data['label'] }}</p>
    @endif

    @if (!empty($data['heading']))
        <h2>{{ $data['heading'] }}</h2>
    @endif

    {{-- Rendered on save, including inside blocks. Fixes Kritano issue 3d. --}}
    {!! $html['body'] ?? '' !!}

    @if (!empty($data['cta_url']) && !empty($data['cta_label']))
        <a class="cta" href="{{ $data['cta_url'] }}">{{ $data['cta_label'] }}</a>
    @endif
</section>
