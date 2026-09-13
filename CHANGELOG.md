# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

While the version is `0.x` the policy DSL and the command surface may change in
any minor release. Composer treats `0.x` as breaking by default, so `^0.1` will
not silently upgrade you to `0.2`.

The findings manifest carries its own `schema_version`, versioned separately and
far more slowly, a newer package should still read an older manifest.

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

[0.1.0]: https://github.com/AsterlaneLabs/privacy-ci/releases/tag/v0.1.0
