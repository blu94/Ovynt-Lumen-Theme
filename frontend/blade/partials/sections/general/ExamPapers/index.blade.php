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
                                        Not a link yet, and deliberately not a dead one. Opening a paper
                                        is a write — it creates the attempt that freezes the case list —
                                        and a theme cannot register a route to accept it. Rendering a
                                        button that 404s would be worse than saying so.
                                    --}}
                                    <button class="lumen-btn lumen-btn--disabled" type="button" disabled
                                            title="{{ __('The exam player is not available yet.') }}">
                                        @switch($paper['state'])
                                            @case('review') {{ __('Review') }} @break
                                            @case('resume') {{ __('Resume') }} @break
                                            @default {{ __('Start') }}
                                        @endswitch
                                    </button>
                                </div>
                            </li>
                        @endforeach
                    </ol>

                    <p class="lumen-papers__note">{{ __('Opening a paper is coming soon.') }}</p>
                @endif
        @endswitch
    </div>
</section>
