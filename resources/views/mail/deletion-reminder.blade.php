<x-mail::message>
# {{ $urgent ? 'Your account is deleted tomorrow' : 'Your account is deleted in '.$days.' days' }}

You asked us to delete your account. It has been closed since then, and on
**{{ $deletesOn->format('j F Y') }}** everything will be permanently erased.

@if ($urgent)
This is the last message you will get about it. After tomorrow nothing can be recovered.
@else
There {{ $days === 1 ? 'is' : 'are' }} {{ $days }} {{ \Illuminate\Support\Str::plural('day', $days) }} left to change your mind.
@endif

<x-mail::button :url="$reactivateUrl">
Keep my account
</x-mail::button>

This link only restores your account, it can never delete anything.

If you do want your account deleted, you don't need to do anything.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
