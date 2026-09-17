@extends('layouts.app')

@php $title = $title ?? 'Calendar'; @endphp

@section('calendar_container')
{{-- Matches original tab.html: .container floats right of #actionbar --}}
<div class="container active">
    <clicktrap></clicktrap>
    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <calendar
        id="calendar-content"
        event-service="CalendarEvents"
        view="{{ $mode === 'week' ? 'week' : 'date' }}"
        data-initial-mode="{{ $mode }}"
        data-initial-date="{{ $currentDate->toDateString() }}"
    >
        @include('calendar.partial')
        @include('calendar.state')
    </calendar>
</div>
@endsection
