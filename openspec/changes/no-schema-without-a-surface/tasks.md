# Tasks: no-schema-without-a-surface

Tier: V1. Kind: code. Row Q11.31. The instrument first, then the triage,
then the retirements.

- [ ] 1.1 `tests/Unit/Architecture/SchemaHasSurfaceTest.php` per D-1, with
  `schema-surface.allowlist.json` seeded with every slug it names on
  `development` (reason: "triage pending, this change") so the build is
  green on day one and the ceiling is the count.
  - fixture register for the orphan and one-hop cases
  - `@spec openspec/changes/no-schema-without-a-surface/specs/quality-gates/spec.md`
- [ ] 1.2 Triage table for every named slug: retire, surface (change), or
  allowlist (reader app), each by `git grep` across dossiq and the sibling
  checkouts. Fill the table in this file.

  | slug | fate | evidence |
  |---|---|---|
  | (filled by 1.2) | | |

- [ ] 2.1 Retire batch 1 (up to five), the `remove-casetask` recipe (D-3),
  ceiling lowered.
- [ ] 2.2 Retire batch 2, and further batches until the retire column is
  empty.
- [ ] 3.1 Surface column: each slug's owning change gains a task naming it;
  the allowlist entry points at that change.
- [ ] 4.1 `openspec validate no-schema-without-a-surface --strict`.
