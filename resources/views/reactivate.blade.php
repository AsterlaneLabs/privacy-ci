@extends('privacy::layout')

@section('title', 'Reactivate your account')

@section('body')
    <h1>Reactivate your account?</h1>

    <p class="notice">
        Your account is closed and scheduled for permanent deletion on
        <span class="date">{{ $deletesOn->format('j F Y') }}</span>,
        {{ $days }} {{ \Illuminate\Support\Str::plural('day', $days) }} from now.
    </p>

    <p>
        Reactivating restores your account and cancels the deletion. Nothing has
        been permanently removed yet.
    </p>

    {{-- POST, not a link: mail scanners and prefetchers follow every URL in an
         email, and a GET that reactivated would cancel deletions nobody asked
         to cancel. --}}
    <form method="POST" action="{{ url()->full() }}">
        @csrf
        <button type="submit">Reactivate my account</button>
    </form>

    <p class="muted">
        If you meant to delete your account, close this page, no action is needed.
    </p>
@endsection
