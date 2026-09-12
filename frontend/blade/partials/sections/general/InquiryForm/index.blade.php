<section class="lumen-inquiry">
    <div class="lumen-shell">
        @if($heading)
            <h2 class="lumen-inquiry__heading">{{ $heading }}</h2>
        @endif

        @if($intro)
            <p class="lumen-inquiry__intro">{{ $intro }}</p>
        @endif

        @if($slug !== '')
            <x-theme.component name="DynamicForm" :data="['slug' => $slug, 'show_title' => $showTitle, 'prefill' => $prefill, 'lock' => $locked]" />
        @elseif(config('app.debug'))
            <div class="lumen-notice">
                <p>{{ __('This Inquiry Form block names no form. Set its Form to the slug of a form under Forms.') }}</p>
            </div>
        @endif
    </div>
</section>
