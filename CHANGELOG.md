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

### Not built yet

- Synthetic-subject verification: sweeping a staging store for an identifier,
  to catch data in places the map never reached.

### Notes

- Laravel 11 is not supported: every 11.x release is subject to a security
  advisory, so Composer refuses to install it.
