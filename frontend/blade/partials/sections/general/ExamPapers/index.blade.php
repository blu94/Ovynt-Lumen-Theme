<section class="lumen-papers">
    <div class="lumen-shell">
        @switch($state)
            @case('signed-out')
                <div class="lumen-notice">
                    <p>{{ __('Sign in to open your exams.') }}</p>
                    <p><a class="lumen-btn" href="/login">{{ __('Sign in') }}</a></p>
                </div>
                @break

            @case('none')
                <div class="lumen-notice">
                    <p>{{ __('You have not started any exams yet.') }}</p>
                    <p><a class="lumen-btn" href="/exams">{{ __('Browse exams') }}</a></p>
                </div>
                @break

            @case('no-access')
                <div class="lumen-notice">
                    <p>{{ $lockedText ?: __('You do not have access to this exam.') }}</p>
                    <p><a class="lumen-btn" href="/exams">{{ __('Browse exams') }}</a></p>
                </div>
                @break

            @case('expired')
                <div class="lumen-notice">
                    <h2>{{ $exam['title'] }}</h2>
                    <p>
                        {{ __('Access ended') }}{{ $exam['expiresOn'] ? ' ' . $exam['expiresOn'] : '' }}.
                        {{ __('Everything you wrote is kept.') }}
                    </p>
                    <p><a class="lumen-btn" href="/exams">{{ __('Renew access') }}</a></p>
                </div>
                @break

            @case('choose')
                <h2 class="lumen-papers__heading">{{ $chooserHeading ?: __('Which exam?') }}</h2>
                <ul class="lumen-papers__choices">
                    @foreach($choices as $choice)
                        <li><a class="lumen-btn" href="/exam/{{ urlencode($choice['slug']) }}">{{ $choice['title'] }}</a></li>
                    @endforeach
                </ul>
                @break

            @default
                <div class="lumen-papers__head">
                    <h2 class="lumen-papers__heading">{{ $exam['title'] }}</h2>
                    <span class="lumen-chip lumen-chip--{{ $exam['mode'] }}">
                        {{ $exam['mode'] === 'timed' ? __('Timed mode') : __('Practice mode') }}
                    </span>
                </div>

                @if($exam['expiresOn'])
                    <p class="lumen-papers__expiry">{{ __('Access until') }} {{ $exam['expiresOn'] }}</p>
                @endif

                {{--
                    Practice or timed is the candidate's choice, and switching clears every report and
                    sitting — the server does the clearing (enrolments.mode), this only asks first.
                    Returning to practice is offered only when the operator allows it.
                --}}
                <script>
                    // One confirm, one action (enrolments.mode), one reload. Bound once per page
                    // however many blocks render, and only to buttons this block draws.
                    if (!window.__lumenModeSwitch) {
                        window.__lumenModeSwitch = true;
                        document.addEventListener('click', async function (e) {
                            var btn = e.target.closest ? e.target.closest('[data-lumen-mode]') : null;
                            if (!btn) return;
                            e.preventDefault();
                            var ask = btn.getAttribute('data-confirm');
                            if (ask && !window.confirm(ask)) return;
                            btn.disabled = true;
                            try {
                                await window.ThemeApi.request('/api/storefront/actions/enrolments.mode', 'POST', {
                                    exam: btn.getAttribute('data-exam'),
                                    mode: btn.getAttribute('data-lumen-mode')
                                });
                                window.location.reload();
                            } catch (err) {
                                btn.disabled = false;
                                var data = (err && err.data) || {};
                                var first = data.errors ? Object.values(data.errors).flat()[0] : null;
                                window.alert(first || data.message || @json(__('That could not be saved. Please try again.')));
                            }
                        });
                    }
                </script>

                @if($exam['mode'] === 'practice')
                    <div class="lumen-papers__mode">
                        <span>{{ __('Practice mode is untimed. Switch to timed mode when you are ready to sit against the clock — this clears everything you have written and restarts every paper.') }}</span>
                        <button class="lumen-btn lumen-btn--ghost" type="button"
                                data-lumen-mode="timed" data-exam="{{ $exam['slug'] }}"
                                data-confirm="{{ __('Switch to timed mode? Every report and sitting under this exam will be cleared and the clocks restarted.') }}">
                            {{ __('Switch to timed mode') }}
                        </button>
                    </div>
                @elseif($allowReversal)
                    <div class="lumen-papers__mode">
                        <button class="lumen-btn lumen-btn--ghost" type="button"
                                data-lumen-mode="practice" data-exam="{{ $exam['slug'] }}"
                                data-confirm="{{ __('Return to practice mode? Every report and sitting under this exam will be cleared.') }}">
                            {{ __('Return to practice mode') }}
                        </button>
                    </div>
                @endif

                @if(empty($papers))
                    <div class="lumen-notice">
                        <p>{{ __('This exam has no papers yet.') }}</p>
                    </div>
                @else
                    <ol class="lumen-papers__list">
                        @foreach($papers as $paper)
                            <li class="lumen-paper" data-state="{{ $paper['state'] }}">
                                <div class="lumen-paper__main">
                                    <h3 class="lumen-paper__title">{{ $paper['title'] }}</h3>
                                    <p class="lumen-paper__meta">
                                        {{ $paper['cases'] }} {{ __('cases') }}
                                        &middot; {{ $paper['minutes'] }} {{ __('min') }}
                                        @if($paper['ended'])
                                            &middot; {{ __('Ended') }}{{ $paper['endedOn'] ? ' ' . $paper['endedOn'] : '' }}
                                            @if($showScores)
                                                &middot; {{ __('Score') }}: {{ $paper['score'] }} / {{ $paper['maxScore'] }}
                                            @endif
                                        @elseif($paper['answered'] > 0)
                                            &middot; {{ $paper['answered'] }} / {{ $paper['cases'] }} {{ __('answered') }}
                                        @endif
                                    </p>
                                </div>

                                <div class="lumen-paper__action">
                                    {{--
                                        /exam/{slug}/{paper} — the player, served through storefront.paths.
                                        Opening it is the write that freezes the case list; the page makes
                                        that call itself (attempts.open), so this is an ordinary link.
                                    --}}
                                    <a class="lumen-btn" href="/exam/{{ urlencode($exam['slug']) }}/{{ urlencode($paper['slug']) }}">
                                        @switch($paper['state'])
                                            @case('review') {{ __('Review') }} @break
                                            @case('resume') {{ __('Resume') }} @break
                                            @default {{ __('Start') }}
                                        @endswitch
                                    </a>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @endif
        @endswitch
    </div>
</section>
