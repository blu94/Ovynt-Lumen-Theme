@extends('layout')

@section('content')
<div class="lumen-shell lumen-page">
    @if(isset($page) && $page->rows && $page->rows->count() > 0)
        @include('components.builder.engine', ['rows' => $page->rows])
    @else
        @if(config('app.debug'))
            <div class="lumen-notice">
                <p>{{ __('The CART page has no builder rows. Add a Cart section to it — that section is the order summary and carries checkout.') }}</p>
            </div>
        @endif
    @endif
</div>
@endsection
