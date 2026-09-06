@extends('layout')

@section('content')
<div class="lumen-shell lumen-page">
    @if(isset($page) && $page->rows && $page->rows->count() > 0)
        @include('components.builder.engine', ['rows' => $page->rows])
    @else
        <div class="lumen-notice">
            <h1>{{ __('Page not found') }}</h1>
            <p>{{ __('The page you were looking for is not here.') }}</p>
            <p><a href="/">{{ __('Back to the home page') }}</a></p>
        </div>
    @endif

    {{-- Rendered outside the branch above, deliberately: a 404 built from builder rows and
         one using this default message are the same page to a visitor, and a plugin that
         suggests a destination should be reached either way. --}}
    <x-plugin-slot name="not-found" :data="['path' => request()->path()]" />
</div>
@endsection
