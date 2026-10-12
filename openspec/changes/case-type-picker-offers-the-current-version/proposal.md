---
kind: code
depends_on: []
---

# Proposal: case-type-picker-offers-the-current-version

## Why

Review on :8098 on 11 October 2026 (N-5) found the New case picker offering
two case types twice:

- **Evenementenvergunning, v1 and v2.** v1 carries `supersededBy` naming v2.
  The spec already says a superseded version SHALL NOT be offered for new
  cases (zaaktype-versioning REQ-ZV-02), and `isCurrentCaseTypeVersion()`
  holds that rule for the start-case widget. The New case form does not use
  it: its picker is CnFormDialog's reference field, which builds its query
  from the `case.caseType` property's `x-relation-filter` and nothing else,
  and that filter only said `isDraft: false`.
- **Omgevingsvergunning kleine bouwactiviteit, two ids.** Not a version: a
  seed duplicate. The case-flow seed looked an existing case type up by its
  title, and the title is translatable, so OpenRegister stores it as a
  language map and the filter on the plain string matched nothing. Every
  repair pass created the case type again. The two copies on :8098 were
  created at 14:08 and 23:34 on 10 October, both by the system.

## What changes

- `case.caseType`'s `x-relation-filter` adds `supersededBy: "IS NULL"`, which
  OpenRegister reads as a null test. The picker offers the version nothing
  has replaced, for every versioned case type, by its data and not by name.
  The `case` schema moves to 1.42.0 so the import picks it up.
- The case-flow seed finds a case type an earlier run stored by the seed's
  slug, in the object metadata, and falls back to the title only for a seed
  row without a slug.

## Out of scope

- Removing the duplicate already stored on an existing install. Both copies
  carry cases (demo cases were seeded under each), so which copy survives
  and where its cases go is a data decision, not something a repair step
  should guess. Named as an open point.
