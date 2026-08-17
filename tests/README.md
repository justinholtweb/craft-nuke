# Tests

## Unit

Pure PHP, no Craft application and no database. What they cover is the part of Nuke that is
deliberately pure: the schedule arithmetic, the settings accessors that decide whether something may
be deleted, and how a target reads the strings a form and a console argument hand it.

```bash
ddev start
ddev composer install
ddev exec vendor/bin/phpunit
```

The DDEV project in `.ddev/` exists so this runs against a pinned PHP, and so `composer install`
here can't disturb the harness the plugin is symlinked into.

Validation rules are *not* unit-tested: their messages go through `Craft::t()`, which needs a booted
Craft application, and booting one to check a validator would make this something other than a unit
suite. They are exercised in the harness instead.

## Integration

Everything that actually deletes something runs against a live Craft — the
[plugin-testing](../../plugin-testing) harness.

`tests/integration/fixtures.php` builds throwaway content to aim a strike at, and tears it down
again: a structure section with two entry types, five doomed entries, a child (so the promoted-
descendant count has something to find), a survivor, drafts, revisions, and relations pointing at
the doomed entries from the survivor.

```bash
# From the harness, so Craft is on the path it expects:
ddev exec php /var/www/craft-nuke/tests/integration/fixtures.php up

ddev exec php craft nuke/strike/fire entries --sources=nukeFixture --types=nukeDoomed
ddev exec php craft nuke/strike/fire entries --sources=nukeFixture --types=nukeDoomed --dry-run=0 --force

ddev exec php /var/www/craft-nuke/tests/integration/fixtures.php down
```

Take a snapshot first — `ddev snapshot --name pre-nuke-test` — because the point of the exercise is
that things get deleted.

### What to check after a strike

- The doomed elements are soft-deleted (`dateDeleted` set), or gone entirely on a hard delete.
- The **child is still alive and its level has changed**. Craft re-parents structure children before
  deleting their parent; it does not delete them. That is what the preview's "descendants moved up"
  count is warning about, and it is the easiest thing in the plugin to get wrong.
- Relations pointing at the deleted elements are cleared on a hard delete. Craft cascades
  `relations.sourceId` but has **no foreign key on `targetId`**, so a permanent delete would
  otherwise leave rows pointing at nothing.
- The run ledger has the note, the backup path, and a preview that matches the outcome.
