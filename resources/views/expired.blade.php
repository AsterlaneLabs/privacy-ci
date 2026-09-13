@extends('privacy::layout')

@section('title', 'This link is no longer valid')

@section('body')
    @if ($reason === 'already')
        <h1>Your account is already active</h1>
        <p>This deletion was cancelled earlier. There is nothing left to do.</p>
    @elseif ($reason === 'done')
        <h1>This account has been deleted</h1>
        <p>
            The deletion period ended and the account was permanently erased.
            It cannot be restored.
        </p>
    @else
        <h1>This link is no longer valid</h1>
        <p>
            It may have expired, or the account may already have been deleted.
            If you think this is wrong, please get in touch.
        </p>
    @endif
@endsection
