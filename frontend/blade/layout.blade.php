<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $seo_title ?? ($settings['site_title'] ?? 'Lumen') }}</title>

    @if(!empty($seo_description))
        <meta name="description" content="{{ $seo_description }}">
    @endif

    @php
        $themeSlug      = $themeConfig['slug'] ?? '';
        $assetRevSuffix = ($assetRev ?? '') !== '' ? '-' . $assetRev : '';
        $accent         = $settings['accent_colour'] ?? '#1e5961';
    @endphp

    @if(file_exists(public_path($path = "themes/{$themeSlug}/frontend/assets/css/theme.css")))
        <link rel="stylesheet" href="{{ asset($path) }}?v={{ filemtime(public_path($path)) }}{{ $assetRevSuffix }}">
    @endif

    <style>:root{--lumen-accent: {{ $accent }};}</style>
</head>
<body>
    @include('partials.layout.header')

    <main class="lumen-main">
        @yield('content')
    </main>

    @include('partials.layout.footer')

    {{--
        Vue 3, self-hosted when the storefront bundle has been built into this theme and from
        the CDN when it has not. Guarded rather than assumed: a viewer that silently fails
        because a network blocked unpkg is worse than one that never shipped.
    --}}
    @php $vuePath = "themes/{$themeSlug}/frontend/assets/js/vendor/vue.global.prod.js"; @endphp
    @if(file_exists(public_path($vuePath)))
        <script src="{{ asset($vuePath) }}?v={{ filemtime(public_path($vuePath)) }}{{ $assetRevSuffix }}"></script>
    @else
        <script src="https://unpkg.com/vue@3/dist/vue.global.prod.js"></script>
    @endif

    @if(file_exists(public_path($sfPath = "themes/{$themeSlug}/frontend/assets/js/storefront.min.js")))
        <script src="{{ asset($sfPath) }}?v={{ filemtime(public_path($sfPath)) }}{{ $assetRevSuffix }}"></script>
    @endif

    @stack('scripts')
</body>
</html>
