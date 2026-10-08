@if ($items !== [])
    <section class="block block-case-study-grid theme-{{ $theme }}">
        @if (!empty($data['heading']))
            <h2>{{ $data['heading'] }}</h2>
        @endif

        @if (!empty($data['subtext']))
            <p class="block-subtext">{{ $data['subtext'] }}</p>
        @endif

        <div class="case-studies">
            @foreach ($items as $item)
                <article class="case-study">
                    @if ($item['category'] !== '')
                        <p class="block-label">{{ $item['category'] }}</p>
                    @endif

                    <h3><a href="{{ $item['url'] }}">{{ $item['title'] }}</a></h3>

                    @if ($item['result'] !== '')
                        <p class="result">{{ $item['result'] }}</p>
                    @endif

                    @if ($item['excerpt'] !== '')
                        <p class="excerpt">{{ $item['excerpt'] }}</p>
                    @endif
                </article>
            @endforeach
        </div>
    </section>
@endif
