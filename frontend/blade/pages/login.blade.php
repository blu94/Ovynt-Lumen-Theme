@extends('layout')

@section('content')
<div class="lumen-shell lumen-page">
    @if(isset($page) && $page->rows && $page->rows->count() > 0)
        @include('components.builder.engine', ['rows' => $page->rows])
    @else
        @if(config('app.debug'))
            <div class="lumen-notice">
                <p>{{ __('The LOGIN page has no builder rows. Add a Login Form section to it — core encrypts the password in the browser, so the form must be the core one.') }}</p>
            </div>
        @endif
    @endif
</div>
@endsection
