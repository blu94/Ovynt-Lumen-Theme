@php
    /**
     * The header menu is authored in theme settings, not hard-coded.
     *
     * A theme cannot know an operator's page slugs. The first build hard-coded them and shipped
     * two dead links: `/articles`, which is `/blogs` on a seeded install, and `/product/{slug}`,
     * where core resolves the plural. Neither errored anywhere except in the visitor's face.
     *
     * So the operator declares the menu (Themes → Settings → Header Menu) and this is only the
     * fallback for a shop that has not. Every URL below is one core actually resolves on a
     * seeded install, checked rather than assumed — except `/exams`, which is a page the
     * operator has to create and which `docs/storefront-pages.md` says so about.
     */
    $fallbackLinks = [
        ['label' => __('Exams'),    'url' => '/exams'],
        ['label' => __('Articles'), 'url' => '/blogs'],
    ];

    $rawLinks = $settings['header_links'] ?? null;

    $headerLinks = collect(is_array($rawLinks) && $rawLinks !== [] ? $rawLinks : $fallbackLinks)
        ->map(function ($l) {
            $label = $l['label'] ?? '';

            // Translatable fields arrive as a locale map from the settings repeater and as a
            // plain string from the fallback above. Resolved here so the markup reads one shape.
            if (is_array($label)) {
                $label = $label[app()->getLocale()] ?? ($label['en'] ?? (count($label) ? reset($label) : ''));
            }

            return [
                'label'   => trim((string) $label),
                'url'     => trim((string) ($l['url'] ?? '')),
                'visible' => ! array_key_exists('visible', $l) || (bool) $l['visible'],
            ];
        })
        ->filter(fn ($l) => $l['visible'] && $l['label'] !== '' && $l['url'] !== '')
        ->values();
@endphp

<header class="lumen-header">
    <div class="lumen-shell lumen-header__inner">
        <a class="lumen-header__brand" href="/">{{ $settings['site_title'] ?? 'Lumen' }}</a>

        <nav class="lumen-header__nav" aria-label="{{ __('Main') }}">
            @foreach($headerLinks as $link)
                <a href="{{ $link['url'] }}">{{ $link['label'] }}</a>
            @endforeach

            {{--
                Account links are not in the menu repeater: which of them applies depends on
                the reader, not on what an operator arranged. `/profile` and `/login` are both
                seeded page slugs, and core's own account guard redirects between them.
            --}}
            <a href="/profile">{{ __('My Account') }}</a>

            <a class="lumen-header__cart" href="/cart">
                {{ __('Cart') }}
                <span data-ovynt-cart-count hidden>0</span>
            </a>
        </nav>
    </div>
</header>
