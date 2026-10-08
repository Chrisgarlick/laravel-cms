{{--
    Queries the article collection, so this block declares
    dependsOn(['article']). That declaration is why publishing an article
    purges every page carrying this block, including the homepage.
--}}
@if ($items !== [])
    <section class="block block-blog-preview theme-{{ $theme }}">
        @if (!empty($data['heading']))
            <h2>{{ $data['heading'] }}</h2>
        @endif

        <ul class="index">
            @foreach ($items as $item)
                <li>
                    <a href="{{ $item['url'] }}">{{ $item['title'] }}</a>

                    @if ($item['excerpt'] !== '')
                        <p class="excerpt">{{ $item['excerpt'] }}</p>
                    @endif

                    @if ($item['date'])
                        <p class="meta">{{ $item['date'] }}</p>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>
@endif
