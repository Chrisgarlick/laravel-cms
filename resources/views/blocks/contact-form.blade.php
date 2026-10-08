{{--
    Placeholder until the Forms module lands in Phase 2.

    When it does, the form will NOT use session CSRF: this page is served from
    the on-disk cache and may have been rendered hours ago, so there is no
    session to tie a token to. It gets a signed expiring HMAC token, a
    honeypot and a rate limit instead. See plan section 5.9.
--}}
<section class="block block-contact-form theme-{{ $theme }}">
    <div class="form-placeholder" data-form="{{ $data['form_slug'] ?? 'contact' }}">
        <p>Contact form ({{ $data['form_slug'] ?? 'contact' }}) renders here from Phase 2.</p>
    </div>
</section>
