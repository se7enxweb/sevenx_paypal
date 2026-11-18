# Upgrading and versions

## Version numbers

`major.minor.patch`, and the **last position counts on**: 1.0.9 is followed by 1.0.10, 1.0.11 ..., never by 1.1.0
just because nine was reached. Moving a higher position is a decision about what the release means (new features:
minor; incompatible changes: major).

Composer constraints with `~` float the last position: `~1.0.0` allows `>=1.0.0 <1.1.0`, so 1.0.10 is inside it.
Tools that pick "the newest tag" must sort by version, not by name (`git tag --sort=version:refname`, `sort -V`).

## A published release is permanent

A tag and the release built from it are never moved, deleted or replaced, and a release's files are never swapped:
Packagist and every installation that resolved a version keep what they got. A mistake in a published version is
fixed by publishing the next one, whose notes say what was wrong with the previous one.

Every release carries its own version in `ezinfo.php` (`Version`), `extension.xml` (`<metadata><version>`) and the
`CHANGELOG.md` entry, set in the commit that is tagged.

## The changelog

[CHANGELOG.md](../CHANGELOG.md) lists every version's changes as `Added:`, `Updated:`, `Fixed:`, `Removed:`,
`Renamed:` lines, newest first; work not released yet is under "Unreleased".

## Upgrading

```bash
composer update se7enxweb/sevenx_paypal
php extension/sevenx_paypal/bin/php/paypal.php --install-schema   # creates tables a new version adds; harmless otherwise
php bin/php/ezpgenerateautoloads.php -e
php bin/php/ezcache.php --clear-tag=ini
php bin/php/ezcache.php --clear-id=template,template-override,template-block,design_base
```

Then reload PHP. Read the version's changelog first: settings that changed are named there.

### PostgreSQL tables created before 1.0.0

Early development builds named the sequences `sevenx_paypal_transaction_s` and `sevenx_paypal_event_s`; the kernel's
PostgreSQL layer reads `<table>_id_seq`. Rename them and point the default at the new name:

```sql
ALTER SEQUENCE sevenx_paypal_transaction_s RENAME TO sevenx_paypal_transaction_id_seq;
ALTER TABLE sevenx_paypal_transaction ALTER COLUMN id SET DEFAULT nextval('sevenx_paypal_transaction_id_seq'::regclass);
ALTER SEQUENCE sevenx_paypal_event_s RENAME TO sevenx_paypal_event_id_seq;
ALTER TABLE sevenx_paypal_event ALTER COLUMN id SET DEFAULT nextval('sevenx_paypal_event_id_seq'::regclass);
```
