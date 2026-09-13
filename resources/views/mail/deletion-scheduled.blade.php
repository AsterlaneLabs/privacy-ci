<x-mail::message>
# Your account is scheduled for deletion

We received a request to delete your account{{ $requestedVia ? ' from '.$requestedVia : '' }}.

**Your account is now closed** and no longer in use. Nothing has been permanently
deleted yet, your data is still recoverable until
**{{ $deletesOn->format('j F Y') }}**, which is
{{ $days }} {{ \Illuminate\Support\Str::plural('day', $days) }} from now.

After that date it will be permanently erased and cannot be restored.

## Changed your mind?

<x-mail::button :url="$reactivateUrl">
Reactivate my account
</x-mail::button>

This link works until {{ $deletesOn->format('j F Y') }} and only restores your
account, it can never delete anything.

If you did ask us to delete your account, you don't need to do anything.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
