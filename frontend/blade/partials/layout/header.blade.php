<header class="lumen-header">
    <div class="lumen-shell lumen-header__inner">
        <a class="lumen-header__brand" href="/">{{ $settings['site_title'] ?? 'Lumen' }}</a>

        <nav class="lumen-header__nav" aria-label="Main">
            <a href="/exams">{{ __('Exams') }}</a>
            <a href="/articles">{{ __('Articles') }}</a>
            @auth
                <a href="/dashboard">{{ __('Dashboard') }}</a>
                <a href="/profile">{{ __('My Account') }}</a>
            @else
                <a href="/login">{{ __('Sign in') }}</a>
            @endauth
            <a class="lumen-header__cart" href="/cart">
                {{ __('Cart') }}
                <span data-ovynt-cart-count hidden>0</span>
            </a>
        </nav>
    </div>
</header>
