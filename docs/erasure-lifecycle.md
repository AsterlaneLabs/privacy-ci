# Erasure lifecycle: suspend, then delete

[Back to the README](../README.md)

- [Why suspend rather than just wait](#why-suspend-rather-than-just-wait)
- [You write suspend and delete; we own the timing](#you-write-suspend-and-delete-we-own-the-timing)
- [Reactivation is deliberate](#reactivation-is-deliberate)
- [Reminders](#reminders)
- [What ends up in the audit trail](#what-ends-up-in-the-audit-trail)

There is no undo. So a deletion request does not erase anything. It **stops processing
immediately** and starts a 14-day clock; only when the clock runs out does anything
become irreversible.

```php
use PrivacyCI\Lifecycle\Laravel\RequestsDeletion;

class User extends Authenticatable
{
    use RequestsDeletion;
}
```

```php
$user->requestDeletion(via: 'account settings');  // suspended now, erased in 14 days
$user->deletionPending();                         // true
$user->isSuspendedForDeletion();                  // true
$user->daysUntilDeletion();                       // 14
$user->reactivate();                              // "actually, keep my account"
```

From the console:

```bash
php artisan privacy:forget 123              # suspend now, erase in 14 days
php artisan privacy:forget 123 --cancel     # reactivate and call it off
php artisan privacy:process-deletions --dry-run
```

`privacy:process-deletions` is scheduled daily at 03:00 and erases only subjects whose
window closed and who did not come back.

## Why suspend rather than just wait

GDPR separates *storing* data from *processing* it. Suspending on day zero means the
request is substantively honoured within hours, and the window becomes a recovery period
for the data rather than a month of doing nothing.

| | Day 0 | Day 14 | Posture |
|---|---|---|---|
| `mode: suspend` *(default)* | processing stops | deleted | Acted immediately, recovery preserved |
| `mode: hold` | nothing changes | deleted | Simpler; a long window means continued processing |
| `grace_days: 0` | deleted | . | No recovery at all. Set it knowingly |

Window length and mode are linked. Art. 12(3) allows one month to respond, so a 30-day
hold spends the entire allowance and leaves nothing if the erasure then fails. If you do
not want suspension, shorten the window instead.

## You write suspend and delete; we own the timing

Two interfaces, both implemented by your application. Neither has a working default:
until they are bound, requests fail loudly, because an app must never be able to tell
someone their account is closed while it stays fully active.

```php
use PrivacyCI\Lifecycle\{DeletionRequest, SubjectSuspender, SubjectDeleter};

class SuspendUser implements SubjectSuspender
{
    public function suspend(DeletionRequest $request): void
    {
        // set status to pending_delete, revoke sessions and tokens, hide the
        // profile, unsubscribe from sends, drop from search and analytics.
        // Nothing here deletes; every change must be undoable below.
    }

    public function reactivate(DeletionRequest $request): void
    {
        // put it all back
    }
}

class DeleteUser implements SubjectDeleter
{
    public function delete(DeletionRequest $request): void
    {
        // your existing DeleteUserJob, unchanged, or the generated handler
    }
}
```

Point `privacy.lifecycle.suspender` and `privacy.lifecycle.deleter` at them.

## Reactivation is deliberate

Under suspension the subject cannot sign in, so login can no longer be the cancellation
signal, and the schedule ignores it in this mode. Instead they get a signed link, and the
package ships the route, the pages and the email.

Send it from your `suspend()`, where you already have the user:

```php
use Illuminate\Support\Facades\Mail;
use PrivacyCI\Mail\DeletionScheduledMail;

public function suspend(DeletionRequest $request): void
{
    $user = User::findOrFail($request->subjectId);

    $user->update(['status' => 'pending_delete']);
    $user->tokens()->delete();
    // ...hide the profile, unsubscribe, drop from indexes

    Mail::to($user)->send(new DeletionScheduledMail($request));
}
```

The package does not try to work out how to contact a subject. Only your application
knows that. Subclass the mailable or publish the views to match your own tone:

```bash
php artisan vendor:publish --tag=privacy-views
```

Three properties of the link, all tested:

- **It reactivates and nothing else.** A link in an inbox is a bearer token. The worst
  case here is an account staying alive that should have been deleted, which the subject
  can simply re-request. A link that could *delete* would let anyone who forwarded it
  destroy an account permanently.
- **It expires exactly when the grace period does.** A link that outlives the window
  implies a rescue that is no longer possible.
- **Opening it changes nothing.** The link renders a confirmation page; the reactivation
  happens on `POST`. Mail scanners and link prefetchers (Outlook Safe Links, corporate
  proxies, some mobile clients) follow every URL in an email, and a one-click `GET`
  would silently cancel deletions nobody asked to cancel.

It does not sign anyone in, either. Restoring the account and proving who you are stay
separate; turning an emailed link into a session is a much larger security surface than
this feature needs.

If you would rather intercept the login attempt instead, the primitives are there:

```php
if ($user->deletionPending()) {
    return view('auth.reactivate', ['days' => $user->daysUntilDeletion()]);
}
```

In `hold` mode the login listener still cancels automatically and no email is needed.

## Reminders

Nobody reads the first email. `remind_days` (default `[7, 1]`) sends a last-chance notice
as the deadline approaches, usually the difference between the grace period working and
not.

```php
use PrivacyCI\Lifecycle\{DeletionRequest, SubjectNotifier};
use PrivacyCI\Mail\DeletionReminderMail;

class NotifySubject implements SubjectNotifier
{
    public function remind(DeletionRequest $request, int $daysRemaining): void
    {
        $user = User::findOrFail($request->subjectId);

        Mail::to($user)->send(new DeletionReminderMail($request, $daysRemaining));
    }
}
```

Point `privacy.lifecycle.notifier` at it. The reminder carries the same signed
reactivation link, and reads more urgently at one day out.

This is a third interface rather than a config'd email column for the same reason as the
other two, but note the difference: the *first* notice is sent by your suspender, which
already has the user. Reminders fire days later from a scheduled command holding nothing
but an id, so resolution has to happen here.

### What the scheduler guarantees, all tested

| | |
|---|---|
| A daily tick | Sends each mark exactly once, never re-sends |
| A missed tick | Still sends, late, rather than skipping the mark |
| A week-long outage | Sends only the most urgent message, not "7 days left" then a correction |
| A failed send | **Not** marked sent; retried next tick, because it is their last chance |
| One failure | Recorded against that subject; everyone else still gets theirs |
| Reactivating | Stops reminders immediately |
| `remind_days: []` | Disables reminders without touching the notifier |

Reminders run *before* erasure on each tick, so a "1 day left" notice can never go out on
the same run that erases the account it refers to.


### Lifecycle guarantees, all covered by tests

| | |
|---|---|
| Asking twice | Returns the original request; you cannot buy another window |
| A failed suspension | Rolls the request back; nobody is told they are closed while active |
| One failed erasure | Recorded against that subject; everyone else still proceeds |
| A failed erasure | Stays visible for a human, never silently retried |
| In-flight requests | Claimed before work starts, so two ticks cannot collide |
| Two open requests | Refused by a database constraint, not just application code |
| Repeated request/cancel | Allowed, each cycle leaving its own audit row |
| An unsigned or tampered link | Rejected with 403 |
| A link followed twice | Shows "already active" rather than erroring |


## What ends up in the audit trail

One row per erasure, in `privacy_deletion_requests`, carrying the whole story:

```text
subject_type       user
subject_id         1
status             completed
requested_at       2026-09-13 04:08:40      requested_via  account settings
suspended_at       2026-09-13 04:08:40      resolved_at    2026-09-13 04:08:41
policy_fingerprint sha256:6f9fb576...       attempts       1
footprint          10 addresses captured before deletion
verification       passed=true  complete=false
evidence hash      sha256:3c89eaaa...
```

`policy_fingerprint` hashes the rules that were in force, so the trail can answer which
policy governed an erasure once the policy has changed. Changing a rule changes the hash,
including a retention reason, since that is the justification an auditor reads.

Nothing here writes to a log channel. It announces the lifecycle instead, and your
application decides what that means:

```php
Event::listen(DeletionRequested::class, LogPrivacyEvents::class);
Event::listen(DeletionCompleted::class, LogPrivacyEvents::class);
```

`DeletionRequested`, `DeletionCancelled`, `DeletionCompleted` and `DeletionFailed` each
carry the request and nothing else, and have no framework dependency. A listener that
throws cannot undo an erasure that already happened.

**What the trail is not.** The row is updated in place, so the transitions
`pending -> suspended -> completed` overwrite one another: you get the outcome and its
timestamps, not the sequence. Rows are not hash chained either, so a deleted row leaves
no trace. The evidence hash proves that evidence is unmodified; it does not prove the set
of rows is complete.

