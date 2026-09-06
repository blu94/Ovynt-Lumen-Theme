@extends('layout')

@section('content')
<div class="lumen-shell lumen-page">
    @if(isset($page) && $page->rows && $page->rows->count() > 0)
        @include('components.builder.engine', ['rows' => $page->rows])
    @else
        @if(config('app.debug'))
            <div class="lumen-notice">
                <p>{{ __('The PROFILE page has no builder rows. Add a Profile section to it.') }}</p>
            </div>
        @endif
    @endif
</div>
@endsection
