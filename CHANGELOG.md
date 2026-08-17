# Release Notes for Nuke

## 5.0.0 - 2026-08-17

Initial release.

### Added

- Strikes: bulk deletion of entries, categories, tags, assets and users, narrowed by source, entry
  type, site, status, age, search or explicit IDs.
- A blast-radius preview that shares its resolved element list with the execution, counts drafts,
  revisions and relations, and lists the surviving elements that reference what is about to go.
- Guardrails: typed confirmation, pre-strike database backup, soft delete by default, an element
  ceiling per run, protected scopes, and a separate permission for permanent deletion.
- 23 housekeeping sweepers across content, database, files and Craft's own garbage collection, each
  with its own retention window.
- A run ledger recording the target, the preview and the outcome of every strike and sweep, plus a
  dedicated `storage/logs/nuke.log` audit trail.
- Console commands `nuke/strike/fire`, `nuke/strike/scopes`, `nuke/sweep/run`, `nuke/sweep/list` and
  `nuke/sweep/due`, all defaulting to a dry run.
- Scheduled sweeps and emailed reports (Pro).
- `Scopes::EVENT_REGISTER_SCOPES` and `Sweepers::EVENT_REGISTER_SWEEPERS` for adding your own, plus
  cancellable `beforeStrike` and `beforeSweep` events.
