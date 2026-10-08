{{--
    Frequently asked questions.

    Structural only, like the other block views: Phase 4 ports the design.

    Rendered as <details> rather than a JavaScript accordion, on purpose. It
    works with no script at all, it is keyboard accessible and announced
    correctly by a screen reader without any ARIA, and the text inside a
    closed <details> is still in the document, so a search engine reads every
    answer. A div-and-onclick accordion gets none of that for free.

    Answers print pre-rendered HTML from `entries.rendered`, at the path the
    observer mirrors from the data: content.<block>.items.<row>.answer.
--}}
@php
    $items = array_values(array_filter(
        (array) ($data['items'] ?? []),
        fn ($item): bool => is_array($item) && filled($item['question'] ?? null),
    ));
@endphp

@if ($items !== [])
    <section class="block block-faq theme-{{ $theme }}">
        @if (filled($data['heading'] ?? null))
            <h2>{{ $data['heading'] }}</h2>
        @endif

        <div class="faq-list">
            @foreach ($items as $index => $item)
                <details class="faq-item">
                    <summary>{{ $item['question'] }}</summary>

                    <div class="faq-answer">
                        {!! data_get($html, "items.{$index}.answer", '') !!}
                    </div>
                </details>
            @endforeach
        </div>
    </section>
@endif
