# Design: no-schema-without-a-surface

## D-1. What counts as a surface

For a slug S, any of: a manifest page or widget with `schema: S`; a
`deepLinks` entry with `schemaSlug: S`; a string literal `'S'` or `"S"`
under `lib/` outside `lib/Settings/`; a `$ref` to S from a schema that
itself has a surface (one hop, so a child of a shown parent counts); an
allowlist entry `{slug, reason, ownerChange | readerApp}`. The test reads
the effective register (all fragments) and resolves aliases through the
`slug` field, not the key.

## D-2. Triage is a table in the tasks file

Each of the 27 gets one line: retire, surface (change named), or allowlist
(reader named). The table is filled in task 1.2 by reading, not guessing:
`git grep -n` per slug across `lib/ src/ tests/ ../*/lib` for sibling
readers.

## D-3. Retire is the remove-casetask recipe

Per schema: grep count recorded, seeds removed, fixture lists updated,
specs that name it get a delta or a note, one PR per batch of no more than
five.

## D-4. The count only goes down

The allowlist carries a ceiling; the test fails when a new schema arrives
without a surface, which is the point.
