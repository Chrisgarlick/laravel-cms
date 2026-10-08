{{-- Hero. Phase 4 ports the real design; this is structure only. --}}
<section class="block block-hero theme-{{ $theme }}">
    @if (!empty($data['label']))
        <p class="block-label">{{ $data['label'] }}</p>
    @endif

    <h1>{{ $data['heading'] ?? '' }}</h1>

    @if (!empty($data['subtext']))
        <p class="block-subtext">{{ $data['subtext'] }}</p>
    @endif

    @if (!empty($data['cta_url']) && !empty($data['cta_label']))
        <a class="cta cta-primary" href="{{ $data['cta_url'] }}">{{ $data['cta_label'] }}</a>
    @endif

    @if (!empty($data['cta_secondary_url']) && !empty($data['cta_secondary_label']))
        <a class="cta cta-secondary" href="{{ $data['cta_secondary_url'] }}">{{ $data['cta_secondary_label'] }}</a>
    @endif
</section>
