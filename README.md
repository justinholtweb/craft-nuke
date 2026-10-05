# Nuke

Delete content in bulk, and keep the site clean afterwards.

Nuke does two related jobs for Craft 5. A **strike** deletes a deliberately chosen set of content —
a section, a volume, a user group — together with its drafts, revisions and relations. A **sweep** is
the recurring housekeeping pass: unreferenced assets, orphaned rows, expired backups and logs, temp
files, and Craft's own garbage collection.

Both are built around the same idea: nothing is deleted until you have seen exactly what would be.

## The guardrails

A plugin that deletes things in bulk should arrive configured to hesitate.

- **Every strike is previewed first.** The preview and the execution share one code path and one
  resolved list of element IDs, so what the preview counts is what the strike deletes. It is not an
  estimate.
- **The preview shows collateral.** Elements that are *not* being deleted but hold a relation to
  something that is — those are the fields that silently empty out, and they are the reason a bulk
  deletion goes wrong quietly rather than loudly.
- **A typed confirmation arms the button.** By default you type the handle of the thing you are
  deleting. The point isn't that typing is hard; it's that it can't happen by accident, by a
  double-click, or by a browser restoring a form on back-navigation.
- **A database backup is taken first**, named after the run, and linked from the run record. If the
  backup fails, nothing is deleted.
- **Soft delete by default.** Elements go to Craft's trash and stay recoverable. Permanent deletion
  is a separate permission.
- **A ceiling on how much one run may touch** — 10,000 elements out of the box. A mis-scoped strike
  fails loudly at the preview instead of quietly at 200,000 rows.
- **Protected scopes** can never be targeted, whatever the form says.
- **Everything is recorded** — in a run ledger, and in `storage/logs/nuke.log`, which survives the
  database it was deleting from.

## What a strike can target

Entries, categories, tags, assets and users. Each narrowed by source (section, volume, group), entry
type, site, status, age, search, or an explicit list of IDs.

Entries and assets can also be narrowed by **author** — the entries someone is an author of, the
assets they uploaded. That includes suspended and inactive users, and users who have been removed
but are still in Craft's trash: soft-deleting a user leaves their authorship on record until
garbage collection deletes the account for good, so there is a window in which "everything this
person wrote" can still be found. The preview says when that window closes, and counts entries
that have other authors too, since deleting those takes the other authors' work with them.

Other element types can register themselves through
`Scopes::EVENT_REGISTER_SCOPES` — see [docs/EXTENDING.md](docs/EXTENDING.md).

Deleting an element takes its drafts and revisions with it either way; Nuke counts them so the
numbers in the report are the truth, and can optionally remove them outright rather than leaving
them soft-deleted.

## What a sweep cleans up

Twenty-three tasks, each with its own window, each opted out of individually.

**Content** — trashed elements past your own window; abandoned and autosave drafts; revisions beyond
the newest few per element (Craft only applies `maxRevisions` when an element is *saved*, so lowering
it never catches up with content nobody edits); unreferenced assets; empty asset folders.

**Database** — relations pointing at deleted elements; stale search index rows; draft change
tracking; element activity; deprecation warnings; failed queue jobs; stale login sessions; expired
tokens; announcements; asset-indexing leftovers; orphaned transform records; bulk-operation records.

**Files** — old database backups; rotated logs; temp and scratch files; compiled templates; config
and Composer backups.

**Craft** — Craft's own garbage collector, run last so it picks up everything the rest orphaned.

The unreferenced-assets task is the one that cannot be certain, and it is built around that fact: it
checks relations *and* reference tags in element content, it reports rather than deletes by default,
and the strongest thing it will ever do is move an asset to the trash.

## Editions

**Lite** is everything above: strikes with the full preview and every guardrail, manual sweeps, the
run ledger, permissions, and the console commands.

**Pro** adds scheduled sweeps and emailed reports.

## From the console

```bash
# What can I aim at?
php craft nuke/strike/scopes

# What would this delete? (--dry-run is on by default)
php craft nuke/strike/fire entries --sources=news --updated-before="-2 years"

# Actually delete it.
php craft nuke/strike/fire entries --sources=news --updated-before="-2 years" --dry-run=0 --force

# Everything someone wrote, and everything they uploaded. Usernames, emails or IDs.
php craft nuke/strike/fire entries --authors=jsmith
php craft nuke/strike/fire assets --authors=jsmith

# Housekeeping.
php craft nuke/sweep/list
php craft nuke/sweep/run                 # scan
php craft nuke/sweep/run --dry-run=0     # sweep
php craft nuke/sweep/due --dry-run=0     # sweep, but only if the schedule says it's owed
```

`--dry-run` defaults to **on** everywhere in the console. A command that deletes by default is a
command that deletes when someone runs it to see what the arguments are.

For cron, either let cron own the schedule:

```
0 3 * * * cd /path/to/site && php craft nuke/sweep/run --dry-run=0
```

…or let Nuke own it and have cron just ask (Pro):

```
*/15 * * * * cd /path/to/site && php craft nuke/sweep/due --dry-run=0
```

## Permissions

| Permission | What it allows |
| --- | --- |
| `nuke:view` | The dashboard, previews and the run ledger |
| `nuke:sweep` | Running housekeeping sweeps |
| `nuke:strike` | Deleting content |
| `nuke:hardDelete` | Bypassing the trash |

`nuke:view` deliberately does not include `nuke:strike`. Seeing what a deletion would do is a
reasonable thing to grant widely; doing it is not.

`nuke:strike` lets someone use Nuke, not delete anything on the site. A non-admin can only strike
sources where Craft's own permissions already let them delete everything:

| Scope | Craft permissions needed on each source |
| --- | --- |
| Entries | Delete entries, and Delete other authors' entries |
| Assets | Delete assets, and Delete other people's assets |
| Categories | Delete categories |
| Tags | None (Craft lets anyone delete a tag) |
| Users | Admin only |

The strike screen only offers those sources. Aiming at every source at once, or at nested entries
by ID, is admin-only. Settings are admin-only, and read-only where `allowAdminChanges` is off.

## Requirements

Craft CMS 5.3 or later, PHP 8.2 or later. No runtime dependencies beyond Craft.

## Installation

```bash
composer require justinholtweb/craft-nuke
php craft plugin/install nuke
```

## Extending

Custom scopes, custom sweepers, and events around both — [docs/EXTENDING.md](docs/EXTENDING.md).

## Licence

The Craft License. See [LICENSE.md](LICENSE.md).
