@extends('layout')

{{--
    /exam/{slug} — served by core's storefront.paths seam through backend/Storefront/ExamPath.php.

    $page here is the PRODUCT the address resolved to, not a Page: the exam itself. The layout a
    candidate sees is still the operator's, though — the Page with slug `exam` carries the Exam
    Papers block and whatever they chose to put around it, and it is the same page that answers
    the bare /exam chooser. So this template renders that Page's rows, and the Exam Papers driver
    reads the exam from the shared $page rather than from the query string.
--}}

@section('content')
<div class="lumen-shell lumen-page">
    @php
        $examLocale = app()->getLocale();
        $examPage = \App\Models\Page::query()
            ->where('status', 'active')
            ->where(function ($q) use ($examLocale) {
                $q->whereJsonContains("slug->{$examLocale}", 'exam')
                  ->orWhereJsonContains('slug->en', 'exam');
            })
            ->with('rows.children.children')
            ->first();
    @endphp

    @if($examPage && $examPage->rows && $examPage->rows->count() > 0)
        @include('components.builder.engine', ['rows' => $examPage->rows])
    @else
        @if(config('app.debug'))
            <div class="lumen-notice">
                <p>{{ __('There is no Page with slug "exam", or it has no builder rows. Create it and add an Exam Papers block — it is the layout for every /exam/{slug} address as well as the chooser at /exam.') }}</p>
            </div>
        @endif
    @endif
</div>
@endsection
