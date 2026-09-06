@php
    $money = function ($amount) use ($currencySymbol, $currencyPosition) {
        $n = number_format((float) $amount, 2);
        return $currencyPosition === 'suffix' ? $n . $currencySymbol : $currencySymbol . $n;
    };
@endphp

<section class="lumen-catalogue">
    <div class="lumen-shell">
        @if($heading)
            <h2 class="lumen-catalogue__heading">{{ $heading }}</h2>
        @endif

        @if($intro)
            <p class="lumen-catalogue__intro">{{ $intro }}</p>
        @endif

        @if(empty($cards))
            <p class="lumen-catalogue__empty">{{ __('No exams are available just yet.') }}</p>
        @else
            <ul class="lumen-catalogue__grid">
                @foreach($cards as $card)
                    <li class="lumen-card" data-state="{{ $card['state'] }}">
                        <h3 class="lumen-card__title">{{ $card['title'] }}</h3>

                        @if($card['subtitle'])
                            <p class="lumen-card__subtitle">{{ $card['subtitle'] }}</p>
                        @endif

                        <dl class="lumen-card__facts">
                            <div>
                                <dt>{{ __('Papers') }}</dt>
                                <dd>{{ $card['papers'] }}</dd>
                            </div>
                            @if($card['duration'])
                                <div>
                                    <dt>{{ __('Duration') }}</dt>
                                    <dd>{{ $card['duration'] }} {{ __('min') }}</dd>
                                </div>
                            @endif
                            <div>
                                <dt>{{ __('Access') }}</dt>
                                <dd>{{ $card['accessDays'] }} {{ __('days') }}</dd>
                            </div>
                        </dl>

                        @if($card['description'])
                            <p class="lumen-card__description">{{ $card['description'] }}</p>
                        @endif

                        <div class="lumen-card__foot">
                            @if($showPrice && $card['price'] !== null)
                                <span class="lumen-card__price">{{ $money($card['price']) }}</span>
                            @elseif($card['state'] === 'free')
                                <span class="lumen-card__price lumen-card__price--free">{{ __('Free') }}</span>
                            @endif

                            @switch($card['state'])
                                @case('open')
                                    <a class="lumen-btn" href="/exam?e={{ urlencode($card['slug']) }}">{{ __('Open') }}</a>
                                    @if($card['expiresOn'])
                                        <span class="lumen-card__note">{{ __('Access until') }} {{ $card['expiresOn'] }}</span>
                                    @endif
                                    @break

                                @case('renew')
                                    @if($card['productSlug'])
                                        <a class="lumen-btn" href="/products/{{ $card['productSlug'] }}">{{ __('Renew access') }}</a>
                                    @endif
                                    <span class="lumen-card__note">{{ __('Your previous access has ended. Everything you wrote is kept.') }}</span>
                                    @break

                                @case('free')
                                    <a class="lumen-btn" href="/exam?e={{ urlencode($card['slug']) }}">{{ __('Start') }}</a>
                                    @break

                                @default
                                    @if($card['productSlug'])
                                        <a class="lumen-btn" href="/products/{{ $card['productSlug'] }}">{{ __('View and buy') }}</a>
                                    @else
                                        <span class="lumen-card__note">{{ __('Not on sale yet.') }}</span>
                                    @endif
                            @endswitch
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</section>
