# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

While the version is `0.x` the policy DSL and the command surface may change in
any minor release. Composer treats `0.x` as breaking by default, so `^0.1` will
not silently upgrade you to `0.2`.

The findings manifest carries its own `schema_version`, versioned separately and
far more slowly, a newer package should still read an older manifest.

## [Unreleased]

### Added

- **Search index support for Elasticsearch and OpenSearch**, end to end.
  - Discovery reads Scout's `Searchable` trait, `searchableAs()` and
    `toSearchableArray()`, including through a searchable base class, and emits
    a deterministic `search_index` finding per indexed model. An unclassified
    index of personal data can now fail CI.
  - Two addressing forms, because a search index is document-addressed:
    `users/{id}` when the subject *is* the document, `comments?user_id={id}`
    when they are a field on documents keyed by something else. A model further
    than one hop from the subject is reported as an index with no addressable
    subject rather than guessing a column that is not a user id.
  - Which cluster a Scout model indexes into is read from the installed driver
    (`matchish/laravel-scout-elasticsearch`, `babenkoivan/elastic-scout-driver`,
    `jeroen-g/explorer`, `elasticsearch/elasticsearch`,
    `opensearch-project/opensearch-php`). Both clients installed at once is
    recorded as ambiguous rather than guessed.
  - `PrivacyCI\Search\SearchIndex`, with a duck-typed client adapter. Neither
    SDK is a dependency of this package; bind whichever one is installed under
    `privacy.search.clients`.
  - `SearchProbe` verifies erasure: an exact document lookup for the document
    form, a count for the query form. A cluster that cannot be reached is
    `UNCHECKED`, never `PASS`.
  - `deleteSearch()` takes a `by:` argument naming the field that holds the
    subject id. `$connection` stays in second position, so existing positional
    calls are unaffected.
- Elasticsearch, OpenSearch and Laravel Scout are now reported as scannable
  rather than as detected-but-unsupported.
- A **store** column in the console report, so where a location physically lives
  is legible without reaching for `--json`. `primary` is dimmed; a detected
  cluster, disk or connection is not.
- Column headers on each report section, in the manifest's own vocabulary
  (`location`, `store`, `linkage`, `confidence`, `classification`). Four
  self-describing columns did not need them; `default` and `scout` do.

### Fixed

- **`deleteSearch()` generated Redis calls.** A search target had no branch of
  its own in the handler generator and fell through to the key-value default, so
  a policy declaring `deleteSearch('users_index')` emitted
  `Redis::del("users_index")` against a search cluster, and imported the Redis
  facade to do it.
- A search location naming only an index used to resolve to a literal key and
  report `absent`/`fail` against the index itself. It is now `UNCHECKED` with the
  reason, and the generated handler leaves a `TODO` instead of a delete.
- **An unstated connection silently overwrote a detected one.** `deleteSearch('users')`
  names no cluster, but the argument's `'default'` fallback was treated as a
  statement, so a store read from `composer.lock` was flattened to `default` and
  the finding gained the evidence line `store named by policy: default` about a
  policy that had said nothing of the sort. An unstated connection now leaves the
  discovered store alone; naming one still wins.
- Integration rows no longer misalign. The source column assumed a fixed width
  that `opensearch-project/opensearch-php` overflows, pushing `scannable` out of
  line on exactly the row a reader most wants to scan.

## [0.1.1] - 2026-09-13

### Changed

- Package metadata a registry displays: homepage, issue tracker and source
  links, and keywords matching the repository topics.
- README installs from Packagist rather than routing through a VCS repository
  and dev stability settings.

## [0.1.0] - 2026-09-13

### Added

- **Discovery** from source alone: migrations, Eloquent models, config and
  `composer.lock`. No database connection, no data access.
  - Migration history is replayed rather than unioned, so a column added and
    later dropped does not appear.
  - Raw `CREATE TABLE` inside `DB::statement()` is parsed, for schemas that
    never used the Blueprint builder.
  - Eloquent relationships link tables the database has no constraint for.
  - Static analysis of application code finds identifiers written to cache,
    object storage and Redis.
- **Policy as code**, `delete`, `anonymize`, `retain`, `ignore`, `custom`, plus
  store rules for Redis, object storage, search and third-party services.
  `retain` and `ignore` require a written reason.
- **`privacy:check`**, fails only on findings that are both deterministic and
  new relative to a committed baseline.
- **`privacy:baseline`**, grandfathers pre-existing findings so adopting the
  tool is a report rather than hundreds of broken builds.
- **Erasure lifecycle**, suspend on request, configurable grace period,
  reactivation by signed link, deadline reminders, scheduled deletion, and
  per-request failure isolation.
- **Verification**, the subject's footprint is captured before deletion and
  checked after. Anything not actually inspected is reported `UNCHECKED`.
- **Generators**, `privacy:make-policy` scaffolds a policy from findings (every
  rule commented out), `privacy:make-handler` writes the deletion handler the
  policy implies.
- **`vendor/bin/privacy-ci`**, runs discovery and the CI check with no Laravel
  boot and no database.

### Added later in development

- `deleteCache()` policy rule, so a key written with `Cache::put()` is cleared
  with `Cache::forget()` rather than by reaching for Redis directly.
- `CacheProbe`, registered by default, which verifies against whatever cache
  driver the application uses.
- Warnings for three policies that cannot execute: a subject root that does not
  exist, anonymising a `NOT NULL` column, and retaining rows that hold a foreign
  key to a row being deleted.

- Lifecycle events (`DeletionRequested`, `DeletionCancelled`,
  `DeletionCompleted`, `DeletionFailed`) so an application can log or mirror the
  erasure lifecycle with its own machinery.
- `policy_fingerprint` recorded against every erasure.
- Masking the subject in place: the root row is updated by its own key, written
  with the placeholder the policy declares, and verified by asking whether
  anything identifying survived rather than whether the row is gone.

### Not built yet

- An append-only transition history, and hash chaining across audit rows.

- Synthetic-subject verification: sweeping a staging store for an identifier,
  to catch data in places the map never reached.

### Notes

- Laravel 11 is not supported: every 11.x release is subject to a security
  advisory, so Composer refuses to install it.

[0.1.1]: https://github.com/AsterlaneLabs/privacy-ci/releases/tag/v0.1.1
[0.1.0]: https://github.com/AsterlaneLabs/privacy-ci/releases/tag/v0.1.0
