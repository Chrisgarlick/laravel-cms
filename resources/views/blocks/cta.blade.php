<section class="block block-cta theme-{{ $theme }}">
    <h2>{{ $data['heading'] ?? '' }}</h2>

    @if (!empty($data['body']))
        <p>{{ $data['body'] }}</p>
    @endif

    @if (!empty($data['cta_url']) && !empty($data['cta_label']))
        <a class="cta cta-primary" href="{{ $data['cta_url'] }}">{{ $data['cta_label'] }}</a>
    @endif
</section>
