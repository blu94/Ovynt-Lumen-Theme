@extends('layout')

{{--
    /exam/{slug}/{paper} — the player. Served by core's storefront.paths seam through
    backend/Storefront/ExamPath.php; $page is the PAPER (Theme\Backend\Models\ExamPaper) with
    its product set.

    Everything the player does is a storefront.actions call — attempts.open on mount, then
    progress pings, reports, the end, marks — made by frontend/assets/js/player.js through
    window.ThemeApi. The markup lives here so its wording goes through __() like every other
    page; the behaviour lives in the script. Vue interpolations are @{{ }} so Blade leaves them.
--}}

@php
    $paperSlug = $page->slug;
    $examSlug  = $page->product?->getTranslation('slug', app()->getLocale(), true)
        ?: ($page->product?->getTranslation('slug', 'en', true) ?? '');
    $backUrl   = '/exam/' . rawurlencode($examSlug);
    $themeSlug = $themeConfig['slug'] ?? '';
    $playerJs  = "themes/{$themeSlug}/frontend/assets/js/player.js";

    // Built here rather than inline in @json(): Blade's directive parser stops at the first
    // closing parenthesis it can pair, and an array of __() calls has many.
    $playerConfig = [
        'paper'   => $paperSlug,
        'exam'    => $examSlug,
        'backUrl' => $backUrl,
        'strings' => [
            'saving'         => __('Saving…'),
            'saved'          => __('Saved'),
            'unsaved'        => __('Not saved'),
            'noAccess'       => __('You do not have access to this exam.'),
            'failed'         => __('That could not be saved. Please try again.'),
            'confirmEnd'     => __('End this paper? You will not be able to change your reports afterwards.'),
            'blank'          => __(':count cases have no report yet.'),
            'blankOne'       => __('One case has no report yet.'),
            'timeUp'         => __('Time is up. The paper has ended.'),
            'endedElsewhere' => __('This paper has ended.'),
            'markSaved'      => __('Mark saved'),
            'switchedMode'   => __('Mode switched.'),
            'timedMode'      => __('Timed mode'),
            'practiceMode'   => __('Practice mode'),
            'reportThis'     => __('Report this study.'),
        ],
    ];
@endphp

@section('content')
<div class="lumen-shell lumen-page lumen-page--player">
    <div id="lumen-player" class="lumen-player" v-cloak>

        <div v-if="state === 'loading'" class="lumen-notice">
            <p>{{ __('Opening the paper…') }}</p>
        </div>

        <div v-else-if="state === 'error'" class="lumen-notice">
            <p>@{{ error }}</p>
            <p><a class="lumen-btn" href="{{ $backUrl }}">{{ __('Back to the exam') }}</a></p>
        </div>

        <template v-else>
            <header class="lumen-player__bar">
                <div class="lumen-player__titles">
                    <a class="lumen-player__back" href="{{ $backUrl }}">&larr; {{ __('Papers') }}</a>
                    <h1 class="lumen-player__title">@{{ attempt.paper.title }}</h1>
                    {{-- No Blade echo inside a Vue interpolation: @{{ }} ends at the first }} it meets --}}
                    <span class="lumen-chip" :class="'lumen-chip--' + attempt.mode">
                        @{{ attempt.mode === 'timed' ? strings.timedMode : strings.practiceMode }}
                    </span>
                </div>

                <div class="lumen-player__clock" :class="{ 'is-low': timed && !ended && remaining < 300, 'is-ended': ended }"
                     :title="timed ? '{{ __('Time remaining') }}' : '{{ __('Time spent') }}'">
                    @{{ clock }}
                </div>

                <div class="lumen-player__actions">
                    <span class="lumen-player__save" :data-state="saveState">@{{ saveLabel }}</span>
                    <button v-if="!ended" class="lumen-btn" type="button" @click="endPaper" :disabled="busy">
                        {{ __('End paper') }}
                    </button>
                    <a v-else class="lumen-btn lumen-btn--ghost" href="{{ $backUrl }}">{{ __('Back to papers') }}</a>
                </div>
            </header>

            <nav class="lumen-player__cases" aria-label="{{ __('Cases') }}">
                <button v-for="(c, i) in cases" :key="c.id" type="button"
                        class="lumen-player__case"
                        :class="{ 'is-current': i === index, 'is-answered': hasReport(c.id), 'is-visited': visited[c.id] }"
                        :title="c.title"
                        @click="go(i)">
                    @{{ c.number }}
                </button>
            </nav>

            <div class="lumen-player__body" v-if="current">
                <section class="lumen-player__viewer" :class="{ 'is-window': tool === 'window' }">
                    <div class="lumen-player__stage"
                         @wheel.prevent="onWheel"
                         @mousedown.prevent="onDown"
                         @dblclick="resetView">
                        {{-- v-on: rather than @error: Blade owns @error and would compile it as its own directive --}}
                        <img v-if="image" :src="image.url" :alt="image.alt || ''" :style="imageStyle"
                             draggable="false" v-on:error="onImageError" v-on:load="onImageLoad">
                        <p v-else class="lumen-player__noimage">{{ __('This case has no image.') }}</p>
                    </div>

                    <div class="lumen-player__tools">
                        <div class="lumen-player__images" v-if="current.images.length > 1">
                            <button v-for="(img, i) in current.images" :key="img.id" type="button"
                                    :class="{ 'is-active': i === imageIndex }" @click="showImage(i)">
                                @{{ i + 1 }}
                            </button>
                        </div>
                        <div class="lumen-player__viewtools">
                            <button type="button" @click="zoomBy(1.25)" title="{{ __('Zoom in') }} (+)">+</button>
                            <button type="button" @click="zoomBy(0.8)" title="{{ __('Zoom out') }} (-)">&minus;</button>
                            <button type="button" @click="rotate" title="{{ __('Rotate') }} (R)">&#8635;</button>
                            <button type="button" :class="{ 'is-active': tool === 'window' }" @click="toggleWindow"
                                    title="{{ __('Window / level: drag to adjust') }} (W)">W/L</button>
                            <button type="button" @click="resetView" title="{{ __('Fit') }} (F)">{{ __('Fit') }}</button>
                        </div>
                    </div>
                </section>

                <section class="lumen-player__panel">
                    <p class="lumen-player__count">{{ __('Case') }} @{{ current.number }} {{ __('of') }} @{{ cases.length }}</p>
                    <h2 class="lumen-player__casetitle" v-if="ended">@{{ current.title }}</h2>
                    <p class="lumen-player__brief" v-if="current.brief">@{{ current.brief }}</p>
                    <p class="lumen-player__instruction">@{{ current.instruction || strings.reportThis }}</p>

                    <label class="lumen-player__label" :for="'report-' + current.id">{{ __('Your report') }}</label>
                    <textarea :id="'report-' + current.id" class="lumen-player__report" rows="8"
                              v-model="reports[current.id]" :readonly="ended"
                              :placeholder="ended ? '{{ __('No report was written.') }}' : '{{ __('Describe the findings and give a diagnosis.') }}'"
                              @input="onReportInput(current.id)" @blur="flush()"></textarea>

                    <template v-if="ended">
                        <h3 class="lumen-player__label">{{ __('Model answer') }}</h3>
                        <div class="lumen-player__model" v-html="modelAnswers[current.id] || '<p>{{ __('No model answer for this case.') }}</p>'"></div>

                        <div class="lumen-player__mark">
                            <label :for="'mark-' + current.id">{{ __('Your mark') }} (0 &ndash; @{{ current.score_max }})</label>
                            <div class="lumen-player__markrow">
                                <input :id="'mark-' + current.id" type="number" step="0.5" min="0" :max="current.score_max"
                                       v-model="marks[current.id]" :disabled="!answers[current.id] || busy">
                                <button class="lumen-btn" type="button" @click="saveMark(current.id)"
                                        :disabled="!answers[current.id] || busy">{{ __('Save mark') }}</button>
                                <span class="lumen-player__markstate">@{{ markState[current.id] || '' }}</span>
                            </div>
                            <p class="lumen-player__hint" v-if="!answers[current.id]">{{ __('Nothing was written for this case, so there is nothing to mark.') }}</p>
                        </div>

                        <p class="lumen-player__total">
                            {{ __('Total') }}: <strong>@{{ total.scored }}</strong> / @{{ total.max }}
                        </p>
                    </template>

                    <div class="lumen-player__nav">
                        <button class="lumen-btn lumen-btn--ghost" type="button" @click="prev" :disabled="index === 0">&larr; {{ __('Previous') }}</button>
                        <button class="lumen-btn lumen-btn--ghost" type="button" @click="next" :disabled="index >= cases.length - 1">{{ __('Next') }} &rarr;</button>
                    </div>

                    <p class="lumen-player__keys">
                        {{ __('Keys') }}: &larr; &rarr; {{ __('cases') }} &middot; + &minus; {{ __('zoom') }} &middot; R {{ __('rotate') }} &middot; W {{ __('window') }} &middot; F {{ __('fit') }}
                    </p>
                </section>
            </div>

            <p v-if="notice" class="lumen-player__notice">@{{ notice }}</p>
        </template>
    </div>
</div>
@endsection

@push('scripts')
<script>
    window.LumenPlayerConfig = @json($playerConfig);
</script>
<script src="{{ asset($playerJs) }}?v={{ file_exists(public_path($playerJs)) ? filemtime(public_path($playerJs)) : '' }}"></script>
<script>
    if (window.LumenPlayer) {
        window.LumenPlayer.mount('#lumen-player', window.LumenPlayerConfig);
    }
</script>
@endpush
