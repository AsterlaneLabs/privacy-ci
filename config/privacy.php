<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | Data subjects
    |---------------------------------------------------------------------------
    |
    | Each subject is a kind of person whose data this application stores, and
    | the column that identifies them. Most applications have exactly one.
    |
    */

    'subjects' => [
        'user' => 'users.id',
    ],

    /*
    |---------------------------------------------------------------------------
    | Discovery
    |---------------------------------------------------------------------------
    |
    | Discovery reads migrations and config from source. It does not connect to
    | the database and never reads a single row, so it is safe to run in CI
    | against a bare checkout with no credentials present.
    |
    */

    'discovery' => [
        'migration_paths' => [database_path('migrations')],
        // Wherever your models actually live. A domain-organised application
        // might use base_path('src/Domain/Models'); an older one might use
        // app_path(). Any namespace works, the scanner reads it from the file.
        'model_paths' => [app_path('Models')],

        // Base classes your models extend, if the scanner cannot work it out.
        // Ancestry is followed automatically when the base class is itself
        // inside a scanned path; this is for bases that live in a package.
        'model_base_classes' => [
            // \Acme\Support\Database\Entity::class,
        ],

        // Stage B: application code to scan for identifiers written outside the
        // database, cache keys, object-storage paths, Redis keys, and documents
        // written into a search cluster through the SDK.
        //
        // Widen this to wherever your code actually lives. A domain-organised
        // application keeps the repository that writes users into OpenSearch
        // well outside app/, and a path that is not scanned reports as an
        // absence of findings rather than as an absence of scanning:
        // [app_path(), base_path('src')]
        //
        // Findings from here are always Linkage::Inferred and can only ever
        // warn. PHP interpolates dynamically and applications wrap everything,
        // so precision is inherently poor; a false positive that blocked a
        // deploy would cost far more than a missed Redis key. Set to [] to
        // switch static analysis off entirely.
        'source_paths' => [app_path()],
        'config_paths' => [config_path()],
        'composer_lock' => base_path('composer.lock'),
    ],

    /*
    |---------------------------------------------------------------------------
    | Policies
    |---------------------------------------------------------------------------
    |
    | Policy classes declaring how each discovered location should be handled.
    | They live in your repository so they are versioned and reviewed in pull
    | requests alongside the code that created the data.
    |
    */

    'policies' => [
        // App\Privacy\UserPrivacyPolicy::class,
    ],

    /*
    |---------------------------------------------------------------------------
    | Erasure lifecycle
    |---------------------------------------------------------------------------
    |
    | When a subject asks to be forgotten, their request enters a grace period
    | rather than erasing anything immediately. Signing back in, or calling
    | cancelDeletion(), calls it off. Once the window closes, the scheduled
    | command erases them.
    |
    | There is no undo, so the window is the safety mechanism. Setting
    | grace_days to 0 erases on the next scheduler tick, so set it knowingly.
    |
    | GDPR Art. 12(3) expects a response without undue delay and within one
    | month. Suspending on day zero means the request is substantively honoured
    | immediately, and the window is then only a recovery period for the data,
    | which is why the default is 14 days of suspension rather than 30 of
    | nothing. Window length and mode are linked: a long window without
    | suspension is the weakest posture available.
    |
    */

    'lifecycle' => [
        // 'suspend' stops processing on day zero and deletes when the window
        // closes. 'hold' changes nothing until the window closes, simpler, but
        // a long window then means a month of continued processing.
        'mode' => 'suspend',

        'grace_days' => 14,

        // Only meaningful in 'hold' mode. Under suspension the subject cannot
        // sign in, so reactivation has to be an explicit act.
        'cancel_on_login' => true,

        // Days before the deadline at which to remind the subject. Nobody reads
        // the first email; this is usually the difference between the grace
        // period working and not. Empty disables reminders entirely.
        'remind_days' => [7, 1],

        // Your implementation of PrivacyCI\Lifecycle\SubjectNotifier, which
        // resolves the subject and sends DeletionReminderMail. Required whenever
        // remind_days is non-empty.
        'notifier' => null,

        // Your implementation of PrivacyCI\Lifecycle\SubjectSuspender. Required
        // in 'suspend' mode; until it is set, requests fail rather than telling
        // a subject their account is closed while it stays fully active.
        'suspender' => null,

        // Cron expression for privacy:process-deletions. Null disables the
        // automatic schedule so you can register it yourself.
        'schedule' => '0 3 * * *',

        // Your implementation of PrivacyCI\Lifecycle\SubjectDeleter. Until this
        // is set, erasures fail loudly rather than silently succeeding.
        'deleter' => null,

        'connection' => null,
        'table' => 'privacy_deletion_requests',
    ],

    /*
    |---------------------------------------------------------------------------
    | Verification
    |---------------------------------------------------------------------------
    |
    | Probes that check whether a subject is really gone. A store with no probe
    | is reported UNCHECKED rather than PASS, a report that silently passes
    | what it never looked at is worse than no report.
    |
    | Remove a probe whose store you do not use; the addresses it would have
    | covered then show as unchecked, which is the honest outcome.
    |
    */

    'verification' => [
        'enabled' => true,

        'probes' => [
            \PrivacyCI\Verification\Probes\DatabaseProbe::class,
            \PrivacyCI\Verification\Probes\StorageProbe::class,
            \PrivacyCI\Verification\Probes\CacheProbe::class,
            \PrivacyCI\Verification\Probes\SearchProbe::class,
            // \PrivacyCI\Verification\Probes\RedisProbe::class,
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Search
    |---------------------------------------------------------------------------
    |
    | The cluster your search indexes live in. Used both by the generated
    | deletion handler and by SearchProbe, deliberately the same client, so that
    | what erasure removed and what verification looks for cannot drift apart.
    |
    | Elasticsearch and OpenSearch are both supported and neither is a
    | dependency of this package: the client is duck-typed, so bind whichever
    | SDK you already have. Point a connection at a class the container can
    | resolve, or at a closure returning a configured client.
    |
    | Note that Scout is an abstraction over engines, not an engine. Scout ships
    | Algolia, Meilisearch, Typesense, database and collection; Elasticsearch has
    | not been first-party since Scout 3, so applications reach it through a
    | community driver or the SDK directly. Discovery reads `use Searchable` to
    | find *which models* are indexed whatever the engine, and reads your
    | installed driver to work out *which cluster* that is. On Algolia or
    | Meilisearch the indexes are still discovered and still classified, and
    | verification reports them UNCHECKED until a client for them is bound here.
    |
    */

    'search' => [
        'clients' => [
            // 'default' => \Elastic\Elasticsearch\Client::class,
            // 'opensearch' => \OpenSearch\Client::class,
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Reactivation flow
    |---------------------------------------------------------------------------
    |
    | The signed link a suspended subject follows to get their account back.
    | The link is generated by PrivacyCI\Lifecycle\Laravel\ReactivationLink and
    | expires exactly when the grace period does.
    |
    | Mail scanners and link prefetchers follow every URL in an email, so the
    | link opens a confirmation page and the reactivation happens on POST. Do
    | not "simplify" that into a one-click GET.
    |
    | The link reactivates and nothing else, it can never delete. A link in an
    | inbox is a bearer token, and the worst case has to stay recoverable.
    |
    */

    'reactivation' => [
        'enabled' => true,
        'prefix' => 'privacy/reactivate',
        'middleware' => ['web'],
    ],

    /*
    |---------------------------------------------------------------------------
    | Confidence thresholds
    |---------------------------------------------------------------------------
    |
    | Findings below `report` are omitted entirely. Findings at or above `high`
    | are presented as likely personal data rather than suggestions.
    |
    | Words that are only personal in context, name, description, reason,
    | message, payload, city, are damped on tables with no route to the
    | subject, which usually drops them below `report`. Lower it to see them:
    | On a lookup table of countries, `name` is "Germany", but a free-text
    | note on a table you own might genuinely be personal.
    |
    */

    'confidence' => [
        'high' => 0.85,
        'report' => 0.25,
    ],

    /*
    |---------------------------------------------------------------------------
    | CI behaviour
    |---------------------------------------------------------------------------
    |
    | Only deterministic findings, foreign keys, declared relationships, may
    | ever fail a build. Heuristic matches warn. A false positive that blocks a
    | deploy costs more trust than a missed column, so this is not configurable.
    |
    | `baseline` grandfathers everything that existed when you adopted the tool,
    | so day one is a warning rather than three hundred failures.
    |
    */

    'ci' => [
        'baseline' => base_path('privacy-baseline.json'),
        'fail_on_new' => true,
    ],

];
