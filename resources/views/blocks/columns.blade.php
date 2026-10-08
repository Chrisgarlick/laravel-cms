{{--
    Four fixed columns, matching the shape the live content is stored in.
    Empty columns are skipped so a two-column page does not emit four slots.
--}}
<section class="block block-columns theme-{{ $theme }}">
    @if (!empty($data['label']))
        <p class="block-label">{{ $data['label'] }}</p>
    @endif

    @if (!empty($data['heading']))
        <h2>{{ $data['heading'] }}</h2>
    @endif

    <div class="columns">
        @foreach ([1, 2, 3, 4] as $n)
            @continue (empty($data["column{$n}_heading"]) && empty($data["column{$n}_body"]))

            @php($tint = $data["column{$n}_tint"] ?? '')

            <div class="column @if($tint) tint-{{ $tint }} @endif">
                @if (!empty($data["column{$n}_heading"]))
                    <h3>{{ $data["column{$n}_heading"] }}</h3>
                @endif

                @if (!empty($data["column{$n}_body"]))
                    <p>{{ $data["column{$n}_body"] }}</p>
                @endif

                @if (!empty($data["column{$n}_url"]))
                    <a href="{{ $data["column{$n}_url"] }}">
                        {{ $data["column{$n}_cta_label"] ?? 'Read more' }}
                    </a>
                @endif
            </div>
        @endforeach
    </div>
</section>
