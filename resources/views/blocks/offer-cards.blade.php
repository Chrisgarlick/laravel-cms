{{-- Features are stored one per line in a textarea, as in the current CMS. --}}
<section class="block block-offer-cards theme-{{ $theme }}">
    <div class="offer-cards">
        @foreach ([1, 2] as $n)
            @continue (empty($data["card{$n}_name"]))

            <article class="offer-card">
                @if (!empty($data["card{$n}_label"]))
                    <p class="block-label">{{ $data["card{$n}_label"] }}</p>
                @endif

                <h3>{{ $data["card{$n}_name"] }}</h3>

                @if (!empty($data["card{$n}_price"]))
                    <p class="price">{{ $data["card{$n}_price"] }}</p>
                @endif

                @if (!empty($data["card{$n}_duration"]))
                    <p class="meta">{{ $data["card{$n}_duration"] }}</p>
                @endif

                @if (!empty($data["card{$n}_features"]))
                    <ul>
                        @foreach (preg_split('/\r\n|\r|\n/', trim($data["card{$n}_features"])) as $feature)
                            @if (trim($feature) !== '')
                                <li>{{ trim($feature) }}</li>
                            @endif
                        @endforeach
                    </ul>
                @endif
            </article>
        @endforeach
    </div>
</section>
