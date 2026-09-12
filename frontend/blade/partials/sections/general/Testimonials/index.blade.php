@if(!empty($items))
<section class="lumen-testimonials">
    <div class="lumen-shell">
        @if($heading)
            <h2 class="lumen-testimonials__heading">{{ $heading }}</h2>
        @endif

        @if($intro)
            <p class="lumen-testimonials__intro">{{ $intro }}</p>
        @endif

        <ul class="lumen-testimonials__grid lumen-testimonials__grid--{{ $columns }}">
            @foreach($items as $item)
                <li>
                    <figure class="lumen-testimonial">
                        @if($item['rating'] > 0)
                            <div class="lumen-testimonial__stars" role="img" aria-label="{{ $item['rating'] }} {{ __('out of 5') }}">
                                @for($i = 1; $i <= 5; $i++)
                                    <svg viewBox="0 0 24 24" width="15" height="15" stroke="currentColor" stroke-width="1.4"
                                         fill="{{ $i <= $item['rating'] ? 'currentColor' : 'none' }}"
                                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="m12 17.75-6.172 3.245 1.179-6.873-4.993-4.867 6.9-1.002L12 2l3.086 6.253 6.9 1.002-4.993 4.867 1.179 6.873z"></path>
                                    </svg>
                                @endfor
                            </div>
                        @endif

                        <blockquote class="lumen-testimonial__quote">{{ $item['quote'] }}</blockquote>

                        <figcaption class="lumen-testimonial__by">
                            @if($item['author'] !== '')
                                <span class="lumen-testimonial__author">{{ $item['author'] }}</span>
                            @endif
                            @if($item['role'] !== '')
                                <span class="lumen-testimonial__role">{{ $item['role'] }}</span>
                            @endif
                        </figcaption>
                    </figure>
                </li>
            @endforeach
        </ul>
    </div>
</section>
@elseif(config('app.debug'))
<section class="lumen-testimonials">
    <div class="lumen-shell">
        <div class="lumen-notice">
            <p>{{ __('No testimonials are published yet. Add one under Testimonials and set it to Published.') }}</p>
        </div>
    </div>
</section>
@endif
