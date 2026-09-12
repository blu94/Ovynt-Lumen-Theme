<section class="lumen-dashboard">
    <div class="lumen-shell">
        @if($heading)
            <h2 class="lumen-dashboard__heading">{{ $heading }}</h2>
        @endif

        @if(! $signedIn)
            <div class="lumen-notice">
                <p>{{ $signedOutText ?: __('Sign in to see your exams.') }}</p>
                <p><a class="lumen-btn" href="/login">{{ __('Sign in') }}</a></p>
            </div>
        @elseif(empty($rows))
            <div class="lumen-notice">
                <p>{{ $emptyText ?: __('You have not started any exams yet.') }}</p>
                <p><a class="lumen-btn" href="/exams">{{ __('Browse exams') }}</a></p>
            </div>
        @else
            <ul class="lumen-dashboard__grid">
                @foreach($rows as $row)
                    <li class="lumen-enrolment @if($row['expired']) lumen-enrolment--expired @endif">
                        <div class="lumen-enrolment__head">
                            <h3 class="lumen-enrolment__title">{{ $row['examTitle'] }}</h3>
                            <span class="lumen-chip lumen-chip--{{ $row['status'] }}">{{ __(ucfirst($row['status'])) }}</span>
                        </div>

                        @unless($row['expired'])
                            <div class="lumen-progress" role="img"
                                 aria-label="{{ $row['progress'] }}% {{ __('answered') }}">
                                <span style="width: {{ $row['progress'] }}%"></span>
                            </div>
                            <p class="lumen-enrolment__meta">
                                {{ $row['answered'] }} / {{ $row['cases'] }} {{ __('cases answered') }}
                                &middot;
                                @if($row['endedPapers'] > 0)
                                    {{ __('Score') }}: {{ $row['score'] }} / {{ $row['maxScore'] }}
                                @else
                                    {{ __('Target') }}: {{ $row['idealScore'] }} / {{ $row['maxScore'] }}
                                @endif
                            </p>
                            @if($row['expiresOn'])
                                <p class="lumen-enrolment__expiry">
                                    {{ __('Access until') }} {{ $row['expiresOn'] }}
                                    @if($row['daysLeft'] <= 7)
                                        <strong>({{ $row['daysLeft'] }} {{ __('days left') }})</strong>
                                    @endif
                                </p>
                            @endif
                        @else
                            <p class="lumen-enrolment__meta">
                                {{ __('Access ended') }}{{ $row['expiresOn'] ? ' ' . $row['expiresOn'] : '' }}.
                                {{ __('Everything you wrote is kept.') }}
                            </p>
                        @endunless

                        <div class="lumen-enrolment__foot">
                            @if($row['examSlug'])
                                @switch($row['action'])
                                    @case('renew')
                                        <a class="lumen-btn" href="/exams">{{ __('Renew access') }}</a>
                                        @break
                                    @case('review')
                                        <a class="lumen-btn" href="/exam/{{ urlencode($row['examSlug']) }}">{{ __('Review answers') }}</a>
                                        @break
                                    @case('resume')
                                        <a class="lumen-btn" href="/exam/{{ urlencode($row['examSlug']) }}">{{ __('Resume') }}</a>
                                        @break
                                    @default
                                        <a class="lumen-btn" href="/exam/{{ urlencode($row['examSlug']) }}">{{ __('Start') }}</a>
                                @endswitch
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</section>
