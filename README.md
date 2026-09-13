# Privacy CI

Find where personal data lives in your Laravel application, classify it in
policy-as-code, and fail CI when new user-linked storage has no policy.

Every backend team can delete a user from their primary database. Almost none can
prove the user is gone from Redis, S3, the search index, the warehouse and six
SaaS vendors. This makes that provable, and catches the next gap at pull-request
time instead of during a regulator's 30-day clock.

**The whole package is free. No account, no limits, no telemetry.**

## Why bother before you have to

Under the GDPR, failing to honour an erasure request sits in the higher penalty
tier: up to 20 million euros or 4% of worldwide annual turnover, whichever is
larger. The UK and most other regimes mirror that shape.

The fine is rarely the expensive part, though. What costs money is the scramble:
a regulator or a customer gives you 30 days, and an engineering team spends them
reconstructing a data map nobody wrote down, hunting through Redis keys and S3
prefixes by hand, under a deadline, on work that ships nothing.

Building the map while nobody is asking takes an afternoon. Building it during
the clock takes a sprint, and you still cannot prove the deletion worked.

## Install

```bash
composer require privacy-ci/laravel
php artisan vendor:publish --tag=privacy-config
php artisan privacy:discover
```

While the version is `0.x` the policy DSL and the command surface may change in
any minor release. Composer treats `0.x` as breaking by default, so `^0.1` will
not quietly move you to `0.2`.

To try the erasure lifecycle as well:

```bash
php artisan vendor:publish --tag=privacy-migrations
php artisan migrate
php artisan privacy:forget 1 --force          # suspends, 14-day countdown
php artisan privacy:forget 1 --cancel         # reactivates
php artisan privacy:process-deletions --dry-run
```

In `suspend` mode (the default) `privacy:forget` will tell you to bind a
`SubjectSuspender` first. It refuses to report an account closed while it is
still fully active.

## Use

```bash
php artisan privacy:discover          # what personal data do we store, and where?
php artisan privacy:discover --json   # emit the findings manifest
```

Or without booting Laravel at all, useful in CI:

```bash
vendor/bin/privacy-ci --path=. --no-ansi
```

```
PERSONAL DATA. HIGH CONFIDENCE

  comments.user_id                 foreign key     1.00 certain
  recommendation_events.device_id  name match      0.98
  users.email                      name match      1.00
  users.id                         subject root    1.00 certain

PERSONAL DATA. POSSIBLE (review required)

  orders.shipping_address  name match      0.80
  users.name               name match      0.65

STORES AND SERVICES DETECTED

  Redis               predis/predis            scannable
  S3                  config/filesystems.php   scannable
  Snowflake           config/services.php      not yet scannable

  1 store detected that this version cannot scan: Snowflake
  Personal data may be flowing there unmapped.

13 locations found · 0 classified · 13 unclassified · 4 would fail CI
```

## The CI gate

```bash
php artisan privacy:baseline   # once, when adopting: forgive existing debt
php artisan privacy:check      # in CI: fail only on what is new
```

```
PRIVACY IMPACT DETECTED

  New user-linked storage with no policy:

    recommendation_events.user_id  recommendation_events.user_id -> users.id

  Classify each in your privacy policy, or run:
    php artisan privacy:baseline    # grandfather pre-existing findings

POSSIBLE PERSONAL DATA. REVIEW, NOT BLOCKING

  users.email     on the subject root table
  sessions.payload  reachable from users in 1 hop(s)

  A name match is never certain, so it will never fail a build.

CHECK FAILED · 1 unclassified location introduced
```

Exit code 1. Two rules decide it, and neither is configurable:

1. **Only deterministic findings can fail**, a foreign key or a declared
   relationship. A name match warns and nothing more. One false positive that
   blocks a deploy costs more trust than a missed column ever will.
2. **Only *new* findings can fail.** Anything in `privacy-baseline.json`
   pre-dates adoption. Without that, dropping this into a mature codebase means
   three hundred failures on day one and a deleted workflow by the afternoon.

`privacy:baseline` refuses to overwrite an existing file without confirmation,
re-baselining is how a team accidentally forgives everything it meant to fix.
Stale entries (a baselined column that no longer exists) are reported, because
leaving them in silently grandfathers a future column that reuses the name.

For gradual adoption, `--warn-only` reports everything and always exits 0.

### Suppression needs a reason

`IGNORE` is the escape hatch, so it is not free:

```php
$this->ignore(FeatureFlag::class)->reason('internal flag, no subject link');
```

`RETAIN` and `IGNORE` both require a written reason, enforced at policy load.
An unexplained suppression is indistinguishable from an oversight when someone
reads the diff two years later.

### Running it anywhere

The gate is one command and an exit code, so any runner works. GitLab, Jenkins,
Buildkite and a git pre-push hook all behave the same way.

```bash
./vendor/bin/privacy-ci --path=. --check --no-ansi     # 0 = pass, 1 = fail
```

Prefer the binary over `php artisan privacy:check` in CI. Artisan has to boot
the application, so it wants an `.env` and an `APP_KEY`; the binary reads the
checkout and nothing else. Neither needs a database.

A complete GitHub Actions workflow:

```yaml
name: privacy

on: pull_request

jobs:
  privacy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          coverage: none

      - name: Cache Composer
        uses: actions/cache@v4
        with:
          path: ~/.composer/cache/files
          key: composer-${{ hashFiles('composer.lock') }}

      - run: composer install --prefer-dist --no-interaction --no-progress

      - name: Privacy check
        run: ./vendor/bin/privacy-ci --path=. --check --no-ansi
```

No database service, no `.env`, no secrets: discovery reads migrations, models,
config and `composer.lock` from the checkout. Verified from a clean clone with
neither file present.

While a team is adopting the check, swap the last step for one that reports
without blocking anyone:

```yaml
      - name: Privacy check (reporting only)
        run: ./vendor/bin/privacy-ci --path=. --check --no-ansi || true
```

`privacy-baseline.json` has to be committed. Without it every run reports the
whole of your existing debt and fails from the first day.

### What it does not do

It does not comment on the pull request. You get a red check and the reason in
the job log. Inline comments need a GitHub App holding a token, which is a
hosted piece rather than something this package can do from inside a job.

## Scaffold the policy

Discovery on a mature codebase finds dozens of locations. Rather than composing
a policy from scratch, generate one to edit:

```bash
php artisan privacy:make-policy
php artisan privacy:make-policy --only-blocking   # just what fails CI today
```

```php
final class UserPrivacyPolicy extends PrivacyPolicy
{
    public function configure(): void
    {
        $this->subject(User::class);

        // ── users ───────────────────────────────────── subject root
        //    email                         0.99  email address
        //    password                      0.95  credential
        //    name                          0.65  possibly a personal name

        // $this->delete(User::class);
        // $this->anonymize(User::class, ['email' => 'deleted@example.invalid', 'name' => 'Deleted user']);
        //   Masking keeps the row so foreign keys survive. Use placeholders,
        //   not null: identifying columns are usually NOT NULL.

        // ── comments ────────────────────────────────── linked by user_id
        //    author_ip                     0.99  IP address
        //    user_id                       1.00  comments.user_id -> users.id

        // $this->anonymize(Comment::class, ['author_ip' => null, 'user_id' => null]);
        // $this->delete(Comment::class);

        // ── subscribers ─────────────────────────────── no link to the subject
        //    email                         0.99  email address

        // No foreign key to the subject, a generated handler cannot look these
        // rows up. Supply the lookup yourself, or rule the table out:
        // $this->custom('subscribers', \App\Privacy\PurgeTheseRows::class);
        // $this->ignore('subscribers')->reason("");
    }
}
```

**Every rule arrives commented out**, and there is a test that fails if one ever
doesn't. Discovery suggests; you decide. An uncommented `delete()` here would
become real deletion code the moment somebody ran `privacy:make-handler`.

Findings are grouped by table with evidence inline, the most plausible rule is
written on the line below, and tables that fail CI today sort first. A table with
no key to the subject gets **no** `delete()` or `anonymize()`
suggestion, neither could address the rows, so offering one would be a lie.

## Policy as code

Rules live in your repository, so they are versioned, reviewed in pull requests,
and deployed with the code that created the data. Changing a table from `RETAIN`
to `DELETE` becomes a code review with a named approver.

```php
namespace App\Privacy;

use App\Models\{Comment, Order, User};
use PrivacyCI\Policy\PrivacyPolicy;

class UserPrivacyPolicy extends PrivacyPolicy
{
    public function configure(): void
    {
        $this->subject(User::class);

        $this->delete(User::class);
        $this->anonymize(Comment::class, ['user_id' => null, 'author_ip' => null]);
        $this->retain(Order::class)->reason('statutory accounting retention, 7y');

        $this->deleteRedis('profile:{id}');
        $this->deleteStorage('avatars/{id}.jpg', disk: 's3');
    }
}
```

Register it in `config/privacy.php`, and findings gain a classification:

```
  comments.user_id       foreign key   1.00 certain   ANONYMIZE
  orders.buyer_id        foreign key   1.00 certain   RETAIN
  users.email            name match    0.99           DELETE
  profile:{id}           declared      1.00 certain   DELETE
  audit_entries.actor_id relationship  1.00 certain   UNCLASSIFIED
```

A rule naming specific columns narrows one covering the whole table. `RETAIN`
without a reason and `CUSTOM` without a handler are rejected outright, an
undocumented retention is the thing an auditor asks about first.

Rules for Redis, object storage, search indexes and third-party services **add**
locations to the map. The developer is describing somewhere the scanner cannot
reach, and a declared location belongs on the map just as much as a found one.

## Models anywhere, not just app/Models

Point `model_paths` wherever your models actually live, any namespace, any
depth. The scanner reads the namespace from the file rather than assuming one:

```php
'discovery' => [
    'model_paths' => [base_path('src/Domain/Models')],
],
```

Relationships resolve across namespaces, and a policy can still name a model by
its short name (`$this->delete(Member::class)` or `'Member'`).

Model detection follows ancestry, so `Invoice extends BaseModel extends Model`
works as long as the base class is inside a scanned path. When it lives in a
package, and isn't named `*Model`, declare it:

```php
'model_base_classes' => [
    \Acme\Support\Database\Entity::class,
],
```

Generated files follow the **application's own PSR-4 map**, read from
`composer.json`, so `--namespace="Acme\Shop\Domain\Privacy"` writes to
`src/Domain/Privacy/` rather than somewhere the autoloader will never look.

## Models are read too

The model scanner catches associations the database does not know about. Plenty
of production schemas declare `belongsTo(User::class)` with no matching foreign
key, the link is real, the migration scanner cannot see it and it is still
deterministic enough to fail a build on.

It also reads `$hidden` and encrypted casts: a developer marking a column
sensitive in the framework's own vocabulary is weak evidence on its own, but it
usefully raises confidence on a column a name heuristic was unsure about.

## Verification: did the erasure actually land?

```bash
php artisan privacy:verify 42
```

```
  avatars/42.png                            UNCHECKED  probe failed: S3 driver not installed
  failed_jobs                               UNCHECKED  no foreign key to the subject; cannot address these rows by id alone
  password_reset_tokens                     UNCHECKED  no foreign key to the subject; cannot address these rows by id alone
  recommendation_events where user_id = 42  PASS
  sessions where user_id = 42               PASS
  user:42                                   UNCHECKED  no probe registered for redis_key
  users where id = 42                       PASS

VERIFIED WITH GAPS · 3 pass · 5 unchecked · sha256:88aa82bf…
```

### The snapshot has to come first

Once the subject's row is gone, the Redis keys, storage paths and dependent rows
that were reachable *from* it can no longer be derived. So the footprint, every
address resolved for that one person, is captured immediately **before**
deletion and checked immediately **after**. `privacy:process-deletions` does
both automatically and attaches them to the request, so one row carries the whole
story: what was there, what ran and what remained.

Verifying without a captured footprint still works, and says what it is:

> No footprint was captured for this subject, so addresses are being derived from
> policy now. That confirms policy coverage; it cannot prove what was removed.

### UNCHECKED is not PASS

The distinction is the entire point. A report that silently passed what it never
looked at would be worse than no report.

| Situation | Outcome |
|---|---|
| Probe looked, found nothing | `PASS` |
| Probe looked, data remains | `FAIL`, exit 1 |
| Kept on purpose | `RETAINED`, with the documented reason |
| No probe for that store | `UNCHECKED` |
| Probe threw (store unreachable) | `UNCHECKED`, with the error |
| PII with no key to the subject | `UNCHECKED`, we know it is there, we cannot address it |

A run with any gaps reports **VERIFIED WITH GAPS**, never **VERIFIED** and exits
non-zero. Each result carries a stable `sha256` fingerprint that excludes the
timestamp, so the same evidence hashes identically whenever it is re-rendered.

Probes ship for database, object storage and Redis; register them in
`verification.probes`. Removing one does not weaken the report, the addresses it
covered simply show as unchecked, which is the honest outcome.

## Generate the deletion handler

Policy-as-code only *describes* the world. `privacy:make-handler` makes it
produce something, at zero runtime risk, because we emit code, not effects.

```bash
php artisan privacy:make-handler
```

```php
final class DeleteUser implements SubjectDeleter
{
    public function delete(DeletionRequest $request): void
    {
        $subjectId = $request->subjectId;

        Storage::disk('s3')->delete("avatars/{$subjectId}.png");

        Redis::del("user:{$subjectId}");

        DB::table('sessions')
            ->where('user_id', $subjectId)
            ->update([
                'ip_address' => null,
                'user_id' => null,
            ]);

        // retained: statutory accounting retention, 7y

        \App\Models\User::query()->whereKey($subjectId)->delete();

        // TODO: these locations have no policy and are therefore untouched.
        //   - recommendation_events.user_id
    }
}
```

It lands in your repository, you review it as a diff and you own it afterwards.
It implements `SubjectDeleter`, so pointing `privacy.lifecycle.deleter` at it
wires the whole erasure lifecycle together.

**Ordering is the hard part, and it is handled.** Non-database stores are cleared
first, once the subject's row is gone, a key pattern built from it can no longer
be resolved. Then rows furthest from the subject, then the subject's own row
last, so foreign keys stay satisfied throughout.

The rest of the behaviour:

| | |
|---|---|
| Unclassified locations | Loud `TODO`s, never silently omitted |
| `RETAIN` | A comment carrying the documented reason, no action |
| `IGNORE` | Absent entirely |
| `CUSTOM` | Calls your handler class |
| A table with no model | Falls back to `DB::table()` |
| PII with no foreign key | `TODO`, we know it is there, you supply the lookup |
| An existing file | Refuses to overwrite without `--force` |
| Unchanged policy | Byte-identical output |

Use `--print` to see it without writing, `--class` and `--namespace` to place it.

## Deleting or masking the subject

Neither is a default. Every location starts unclassified and every scaffolded
rule arrives commented out, so the choice is made once, in your policy, and
reviewed like any other change.

For the subject's own row `privacy:make-policy` offers both, because neither is
obviously right:

```php
// $this->delete(User::class);
// $this->anonymize(User::class, ['email' => 'deleted@example.invalid', 'name' => 'Deleted user']);
```

**Deleting** forces every foreign key pointing at the row to be nullable or
cascading, or the delete is refused. **Masking** keeps orders, comments and audit
rows attributable to somebody, just not to a person.

Mixing is fine and usual: mask the subject, delete the sessions, anonymise the
comments, keep the orders.

### Masking

```php
$this->anonymize(User::class, [
    'name' => 'Deleted user',
    'email' => 'deleted@example.invalid',
    'password' => '',
]);
```

**Mask to placeholders, not to null.** On most subject tables the identifying
columns are `NOT NULL`, so an update to null fails at erasure time. If you try,
`privacy:make-handler` says so before you ship it:

> Anonymising sets these to null, but the schema declares them NOT NULL, so the
> erasure will fail: users.email, users.name. Make the columns nullable, or
> delete the rows instead of anonymising them.

Three things behave differently for the subject's own row:

| | |
|---|---|
| It is found by its own key | not by a foreign key it does not have |
| The update is not chunked | one row, and the key must survive, so a loop would never end |
| Verification asks a different question | not "is the row gone" but "did anything identifying survive" |

That last one matters. A masked subject that still held its old email would pass
an absence check, since the row is meant to be there. Verification compares each
masked column against the value the policy declared:

```
users where id = 1, masked    PASS
users where id = 1, masked    FAIL    data still present
```

The second line is a real run after putting the original email back.

If your email column is unique, give each erased subject a distinct placeholder,
or the second erasure collides with the first.

## Self-service erasure: suspend, then delete

There is no undo. So a deletion request does not erase anything. It **stops
processing immediately** and starts a 14-day clock; only when the clock runs out
does anything become irreversible.

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

### Why suspend rather than just wait

GDPR separates *storing* data from *processing* it. Suspending on day zero means
the request is substantively honoured within hours, and the window becomes a
recovery period for the data rather than a month of doing nothing.

| | Day 0 | Day 14 | Posture |
|---|---|---|---|
| `mode: suspend` *(default)* | processing stops | deleted | Acted immediately, recovery preserved |
| `mode: hold` | nothing changes | deleted | Simpler; a long window means continued processing |
| `grace_days: 0` | deleted | . | No recovery at all. Set it knowingly |

Window length and mode are linked. Art. 12(3) allows one month to respond, so a
30-day hold spends the entire allowance and leaves nothing if the erasure then
fails. If you do not want suspension, shorten the window instead.

### You write suspend and delete; we own the timing

Two interfaces, both implemented by your application. Neither has a working
default, until they are bound, requests fail loudly, because an app must never
be able to tell someone their account is closed while it stays fully active.

```php
use PrivacyCI\Lifecycle\{DeletionRequest, SubjectSuspender, SubjectDeleter};

class SuspendUser implements SubjectSuspender
{
    public function suspend(DeletionRequest $request): void
    {
        // set status to pending_delete, revoke sessions and tokens, hide the
        // profile, unsubscribe from sends, drop from search and analytics.
        // Nothing here deletes, every change must be undoable below.
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
        // your existing DeleteUserJob, unchanged
    }
}
```

Point `privacy.lifecycle.suspender` and `privacy.lifecycle.deleter` at them.

### Reactivation is deliberate

Under suspension the subject cannot sign in, so login can no longer be the
cancellation signal, and the schedule ignores it in this mode. Instead they get
a signed link, and the package ships the route, the pages and the email.

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

The package does not try to work out how to contact a subject. Only your
application knows that. Subclass the mailable or publish the views to match your
own tone:

```bash
php artisan vendor:publish --tag=privacy-views
```

Three properties of the link, all tested:

- **It reactivates and nothing else.** A link in an inbox is a bearer token. The
  worst case here is an account staying alive that should have been deleted,
  which the subject can simply re-request. A link that could *delete* would let
  anyone who forwarded it destroy an account permanently.
- **It expires exactly when the grace period does.** A link that outlives the
  window implies a rescue that is no longer possible.
- **Opening it changes nothing.** The link renders a confirmation page; the
  reactivation happens on `POST`. Mail scanners and prefetchers. Outlook Safe
  Links, corporate proxies, some mobile clients, follow every URL in an email,
  and a one-click `GET` would silently cancel deletions nobody asked to cancel.

It does not sign anyone in, either. Restoring the account and proving who you
are stay separate; turning an emailed link into a session is a much larger
security surface than this feature needs.

If you would rather intercept the login attempt instead, the primitives are
there:

```php
if ($user->deletionPending()) {
    return view('auth.reactivate', ['days' => $user->daysUntilDeletion()]);
}
```

In `hold` mode the login listener still cancels automatically and no email is
needed.

### Reminders

Nobody reads the first email. `remind_days` (default `[7, 1]`) sends a
last-chance notice as the deadline approaches, usually the difference between
the grace period working and not.

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

This is a third interface rather than a config'd email column for the same
reason as the other two, but note the difference: the *first* notice is sent by
your suspender, which already has the user. Reminders fire days later from a
scheduled command holding nothing but an id, so resolution has to happen here.

What the scheduler guarantees, all tested:

| | |
|---|---|
| A daily tick | Sends each mark exactly once, never re-sends |
| A missed tick | Still sends, late, rather than skipping the mark |
| A week-long outage | Sends only the most urgent message, not "7 days left" then a correction |
| A failed send | **Not** marked sent; retried next tick, because it is their last chance |
| One failure | Recorded against that subject; everyone else still gets theirs |
| Reactivating | Stops reminders immediately |
| `remind_days: []` | Disables reminders without touching the notifier |

Reminders run *before* erasure on each tick, so a "1 day left" notice can never
go out on the same run that erases the account it refers to.

### From the console

```bash
php artisan privacy:forget 123              # suspend now, erase in 14 days
php artisan privacy:forget 123 --cancel     # reactivate and call it off
php artisan privacy:process-deletions --dry-run
```

`privacy:process-deletions` is scheduled daily at 03:00 and erases only subjects
whose window closed and who did not come back.

### Guarantees, all covered by tests

| | |
|---|---|
| Asking twice | Returns the original request, you cannot buy another window |
| A failed suspension | Rolls the request back; nobody is told they are closed while active |
| One failed erasure | Recorded against that subject; everyone else still proceeds |
| A failed erasure | Stays visible for a human, never silently retried |
| In-flight requests | Claimed before work starts, so two ticks cannot collide |
| Two open requests | Refused by a database constraint, not just application code |
| Repeated request/cancel | Allowed, each cycle leaving its own audit row |
| An unsigned or tampered link | Rejected with 403 |
| A link followed twice | Shows "already active" rather than erroring |

## Static analysis finds what the schema cannot

Plenty of personal data never reaches a column. Stage B reads your application
code for identifiers written to cache, object storage and Redis:

```php
Cache::put("user:{$user->id}", $payload);
Storage::disk('s3')->put("avatars/{$user->id}.jpg", $file);
Redis::set('profile:' . $user->id, $json);
```

```
  user:{user.id}         inferred   0.80   UNCLASSIFIED
  avatars/{user.id}.jpg  inferred   0.80   UNCLASSIFIED
```

Patterns keep the interpolated expression, `user:{user.id}`, not `user:*`,
because a developer reading the report can judge the first at a glance and
cannot judge the second at all. Concatenation and interpolation both work, and
`Storage::disk('s3')` attributes the write to that disk rather than the default.

**Precision here is inherently poor**, and the design admits it. PHP interpolates
dynamically, applications wrap everything in repositories and helpers and a key
is only recognisable as personal by how it is *named*, `user:{$userId}` and
`report:{$reportId}` are identical in shape. So every Stage B finding is
`inferred`, which means it can only ever warn:

| | |
|---|---|
| `report:{reportId}` | Ignored, names something other than the subject |
| `app:config` | Ignored, wholly literal, cannot key on a person |
| `Cache::get(...)` | Ignored, a read is not a write |
| Same key, three files | One finding, not three |
| Any finding at all | `inferred` · warns · never fails CI |

A false positive that blocks a deploy costs the customer permanently; a missed
Redis key costs one review. Set `discovery.source_paths` to `[]`, or pass
`--no-static`, to switch it off.

## It never touches your database

Discovery reads **migrations, config and `composer.lock` from source**. It does not
open a database connection and never reads a single row, so it runs safely in CI
against a bare checkout with no credentials present.

Migrations are replayed as a *history*, not unioned: a column added in 2021 and
dropped in 2023 does not appear in the results.

**Raw SQL migrations are read too.** Long-lived applications often never used the
Blueprint builder, their schema arrived as a dump wrapped in `DB::statement()`,
and the table it defines is usually the oldest and most important one:

```php
DB::statement("CREATE TABLE `legacy_members` (
  `member_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `state` enum('Active','Suspended','Pending Close') NOT NULL,
  PRIMARY KEY (`member_id`),
  CONSTRAINT `fk` FOREIGN KEY (`owner_id`) REFERENCES `legacy_members` (`member_id`)
) ENGINE=InnoDB;");
```

Columns, nullability and inline `FOREIGN KEY` constraints are all extracted, and
`enum('Active','Pending Close')` does not shred the column list the way a naive
comma split would.

If your subject table is not `users.id`, say so:

```php
'subjects' => ['user' => 'legacy_members.member_id'],
```

## Certain findings versus likely ones

Every finding carries a confidence and a linkage explaining why we believe it.

| Linkage | Example | Can fail CI |
|---|---|---|
| `subject_root` | `users.id` | yes |
| `foreign_key` | `comments.user_id → users.id` | yes |
| `relationship` | `belongsTo(User::class)` with no constraint | yes |
| `declared` | named in a privacy policy | yes |
| `heuristic` | `orders.shipping_address` | **no, warns only** |
| `inferred` | `Cache::put("user:{$user->id}", …)` | **no, warns only** |

Only deterministic findings may ever fail a build. A false positive that blocks a
deploy costs more trust than a missed column, so this is not configurable.

## The findings manifest

Everything is an operation on one document. Diff it across commits and you have
the CI check; sign and retain it and you have audit evidence; join several and you
have a cross-service map.

```json
{
  "schema_version": "1.0",
  "project": "acme/api",
  "subjects": [{ "type": "user", "root": "users.id" }],
  "locations": [
    {
      "id": "db:primary:comments.user_id",
      "kind": "database_column",
      "store": "primary",
      "path": "comments.user_id",
      "subject": "user",
      "linkage": "foreign_key",
      "confidence": 1,
      "classification": "UNCLASSIFIED",
      "evidence": ["comments.user_id -> users.id"]
    }
  ]
}
```

It carries structure and identifiers, **never values**. Serialisation is
deterministic, so two scans of unchanged code produce byte-identical output,
without which diffing would be meaningless.

## What ends up in the audit trail

One row per erasure, in `privacy_deletion_requests`, carrying the whole story:

```
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

`policy_fingerprint` hashes the rules that were in force, so the trail can
answer which policy governed an erasure once the policy has changed. Changing a
rule changes the hash, including a retention reason, since that is the
justification an auditor reads.

### Events, for your own logging

Nothing here writes to a log channel. It announces the lifecycle instead, and
your application decides what that means:

```php
Event::listen(DeletionRequested::class, LogPrivacyEvents::class);
Event::listen(DeletionCompleted::class, LogPrivacyEvents::class);
```

`DeletionRequested`, `DeletionCancelled`, `DeletionCompleted` and
`DeletionFailed` each carry the request and nothing else, and have no framework
dependency. A listener that throws cannot undo an erasure that already happened.

### What this is not

The row is updated in place, so the transitions `pending -> suspended ->
completed` overwrite one another: you get the outcome and its timestamps, not
the sequence. Rows are not hash chained either, so a deleted row leaves no
trace. The evidence hash proves that evidence is unmodified. It does not prove
the set of rows is complete.

## What this is not

The output is **engineering evidence, not legal certification**.

`privacy:check` passing means no *deterministic, newly introduced* user-linked
storage lacks a policy. It does not mean the application is compliant with the
GDPR, the CCPA or anything else. `VERIFIED` means every address in a captured
footprint was inspected and found clear, it says nothing about data in stores
that were never mapped, addresses reported `UNCHECKED` or personal data the
heuristics did not recognise.

Three limits worth stating plainly:

- **Discovery is incomplete by construction.** It finds what migrations, models,
  config and code reveal. Data written by a service you do not own, by raw SQL
  the parser could not read, or by a key built dynamically at runtime is not in
  the map, and a location that is not in the map is never verified.
- **Heuristic findings are guesses.** A column matching a name pattern may hold
  nothing personal; a column matching nothing may hold a great deal. That is why
  they only ever warn.
- **Verification proves absence at known addresses.** It cannot prove that the
  set of known addresses was complete.

Deciding what counts as personal data, what must be erased and what may be
retained is a legal judgement this tool does not make. It gives an engineering
team a map, a gate and a record, take those to whoever owns that judgement.

Provided under the MIT licence, without warranty of any kind.

## Development

```bash
composer install
vendor/bin/phpunit --testdox            # unit + feature
vendor/bin/phpunit --testsuite Unit     # no Laravel boot required
./bin/privacy-ci --path=tests/fixtures/demo-app --no-ansi
```

The demo app under `tests/fixtures/demo-app` wires migrations, models and a
policy into the conventional Laravel locations, so it exercises the whole path.
```

### Working against a real application

To develop against an app on the same machine, point Composer at the checkout
rather than the repository. Composer symlinks it, so edits take effect with no
reinstall:

```jsonc
"repositories": [
    { "type": "path", "url": "../privacy-ci", "options": { "symlink": true } }
],
```

```bash
composer require privacy-ci/laravel:@dev
```

A path repository resolves to `dev-main` from the branch name, which is why the
`@dev` constraint and `minimum-stability: dev` are needed. Neither applies to a
normal install, which resolves a tagged version from Packagist.

## License

MIT.
