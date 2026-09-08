# Tasks: lists-that-answer

Tier: V1. Kind: config. Phase 3 change DQ3. Every checkbox is one
implementation task; the criteria under it are plain bullets.

## 0. Re-verify before building

- [x] 0.1 Re-read rows 3.5, 9.11 and 13.6 against `development`, because the
  ratings predate the round 2 implementations.
  - 3.5 asks for My, Unassigned, Team and All on Tasks. Three of the four
    already ship: `#Tasks/quickFilters` carries All (default), Mine and
    Unclaimed. Its note "no unassigned or team presets" is stale on the
    unassigned half. Only the Team quarter is open.
  - 13.6's filter half is further along than the row says: `assignedGroup` is
    `facetable`, `#Cases/extend` expands it, and the Team column reads the
    expanded `roleName`. The chip is what is missing, not the facet.
  - 9.11 is open. The Tasks index has generic columns and no date lens.

## 1. Lenses on Tasks

- [x] 1.1 `src/manifest.json` page `Tasks`: extend `config.quickFilters` with
  Closed, Overdue and Due this week, so the six labels match `Cases` exactly.
  - `@spec openspec/changes/lists-that-answer/specs/task-management/spec.md`
  - Flat bracket keys (`dueDate[lt]`, `dueDate[gte]`), never the nested
    operator object, for the reason `one-case-list` recorded on `Cases`.
  - `lt` on the far edge, so day seven belongs to next week.
  - `_quickFiltersNote` records that `dueDate` is `format: date-time` while
    `deadline` is `format: date`, and that OpenRegister casts only numeric
    columns, so both windows are plain string comparisons in which an instant
    sorts after its own date prefix. That is what makes a task due at nine
    this morning due today rather than overdue.

## 2. The priority column

- [x] 2.1 `src/manifest.json` page `Tasks`: declare `priority` after
  `dueDate`.
  - `@spec openspec/changes/lists-that-answer/specs/task-management/spec.md`
  - REQ-TASK-004's first scenario has always named priority in the task row.
    The property is `facetable`, so it was reachable through the sidebar and
    invisible in the row.

## 3. The matching lens on Cases

- [x] 3.1 `src/manifest.json` page `Cases`: add Due this week over `deadline`.
  - `@spec openspec/changes/lists-that-answer/specs/case-management/spec.md`

## 4. Tests

- [x] 4.1 `tests/vitest/caseListLenses.spec.js`: one `LENSES` literal asserted
  against BOTH pages, the two window shapes, the Closed shape, the priority
  column and its position, and the `@today+7d` token checked against
  nextcloud-vue's own `TODAY_DELTA_RE` rather than a copy of it.
  - Every new assertion was mutation-checked: seven mutations, each reddening
    the one assertion that names it and no other.
- [x] 4.2 `tests/e2e/case-list-lenses.spec.ts`: four dated task fixtures
  including one due at nine this morning, six new scenarios, and an API-level
  window assertion that cannot be confused by pagination.

## 5. Deferred, with the reason

- [ ] 5.1 The Team chip on both indexes (3.5's fourth quarter, 13.6's chip).
  Blocked on dossiq, not on nextcloud-vue: `assignedGroup` and `assigneeGroup`
  point at an `organisatieRol`, `medewerkerRolToewijzing` is the membership
  table and has zero readers and zero writers, and the library seam
  (`@workspace.<key>` resolved by `useSelfFetchList`) already exists. See
  proposal decision D3.
- [ ] 5.3 nextcloud-vue: a facet option shows its count and its label. Two one-
  line defects found while checking whether the Team facet covers the gap. The
  store reads `b.count` where OpenRegister's bucket carries `results`, so every
  count is 0; and it drops `b.label`, so a `$ref` facet lists uuids. Filed for
  the platform queue, not fixed here: the blast radius is every index page in
  21 apps.
- [ ] 5.2 Shared saved views by department or role (row 9.4). Owned by
  nextcloud-vue's `saved-views-shared-by-role`, which has a merged spec and no
  implementation.
