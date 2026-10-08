{{--
    Two authored teasers, falling back to the tool collection when they are
    empty. The dependency is declared either way, so adding a tool cannot
    leave this stale.
--}}
<section class="block block-tools-teaser theme-{{ $theme }}">
    @if (!empty($data['label']))
        <p class="block-label">{{ $data['label'] }}</p>
    @endif

    <div class="tools">
        @forelse (array_filter([
            ['name' => $data['tool1_name'] ?? '', 'body' => $data['tool1_body'] ?? ''],
            ['name' => $data['tool2_name'] ?? '', 'body' => $data['tool2_body'] ?? ''],
        ], fn ($t) => $t['name'] !== '') as $tool)
            <article class="tool">
                <h3>{{ $tool['name'] }}</h3>
                @if ($tool['body'] !== '')
                    <p>{{ $tool['body'] }}</p>
                @endif
            </article>
        @empty
            @foreach ($items as $item)
                <article class="tool">
                    <h3><a href="{{ $item['url'] }}">{{ $item['title'] }}</a></h3>
                    @if ($item['excerpt'] !== '')
                        <p>{{ $item['excerpt'] }}</p>
                    @endif
                </article>
            @endforeach
        @endforelse
    </div>
</section>
