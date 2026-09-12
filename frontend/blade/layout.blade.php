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

    {{--
        Vue 3, in the head and synchronous, because core's own sections — the login and
        register forms, the cart — carry inline scripts that call window.Vue as they parse.
        Loaded at the end of the body it arrives after them, and every one of those forms
        dies with "Vue is not defined". Self-hosted when the storefront bundle has been built
        into this theme, from the CDN when it has not; guarded rather than assumed.
    --}}
    @php $vuePath = "themes/{$themeSlug}/frontend/assets/js/vendor/vue.global.prod.js"; @endphp
    @if(file_exists(public_path($vuePath)))
        <script src="{{ asset($vuePath) }}?v={{ filemtime(public_path($vuePath)) }}{{ $assetRevSuffix }}"></script>
    @else
        <script src="https://unpkg.com/vue@3/dist/vue.global.prod.js"></script>
    @endif

    {{--
        The theme API core's sections call — window.ThemeApi.auth.login() and its siblings.
        Authored in the theme, like Ella's and Saffron's: core ships the forms and the theme
        ships the client that talks to /api for them. Without it the login form throws before
        it sends anything, and shows "Invalid credentials" for a password that was right.
    --}}
    @php $apiJsPath = "themes/{$themeSlug}/frontend/assets/js/api.js"; @endphp
    <script src="{{ asset($apiJsPath) }}?v={{ file_exists(public_path($apiJsPath)) ? filemtime(public_path($apiJsPath)) : '' }}{{ $assetRevSuffix }}"></script>

    {{-- The token cookie is HttpOnly, so script cannot read it; the server says whether one is present. --}}
    <script>window.AlvythAuthHint = @json((bool) (request()->cookie('customer_access_token') ?: ($_COOKIE['customer_access_token'] ?? null)));</script>

    @if(file_exists(public_path($sfPath = "themes/{$themeSlug}/frontend/assets/js/storefront.min.js")))
        <script src="{{ asset($sfPath) }}?v={{ filemtime(public_path($sfPath)) }}{{ $assetRevSuffix }}"></script>
    @endif
</head>
<body>
    @include('partials.layout.header')

    <main class="lumen-main">
        @yield('content')
    </main>

    @include('partials.layout.footer')

    @stack('scripts')
</body>
</html>
