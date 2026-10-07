---
kind: config
depends_on: []
---

# Proposal: archived-cases-leave-the-lenses

Competitor gap register, row Q2.33 "Can a closed case be archived, hidden
from working views and search, read-only, and restored, without being
deleted" (`procest/_gaps/gap-register.md` in
ConductionNL/market-intelligence, 2026-09-13). Rated partial, owner
openregister, slug `object-archive-state`, size M. This change is dossiq's
half, opened in this lane because the half is a surface: an Archive
action, an Archived lens and one mapping. The state itself is
openregister's `object-archive-state`.

## Why

A closed case stays in the list, in the search results and in every count,
for as long as the retention rule says it must exist. A desk that handled
four thousand cases last year works in a list that still holds them.

The register's note: "`archiefstatus` is ZGW data on the zaak, defaulting
to `nog_te_archiveren` (`lib/Service/ZgwZrcRulesService.php:121`) and
guarded on delete: 'Archived zaken cannot be deleted without the
zaken.geforceerd-verwijderen scope.' (`lib/Controller/ZrcController.php:1265`).
Nothing hides an archived zaak from a list or makes it read-only; the
status is a fact about the archive, not a state of the working view".

The best competitors in the register, verbatim from its `best` column:
"OTOBO 11.0 and Znuny 7.3: ticket.archive_flag with RestoreFromArchive,
driven in batch 5; Odoo 19.0 action_archive, driven in batch 6
(`_round4/compare/proposed-rows-batch10.md`)".

## What changes

- Archive on `#CaseDetail`, visible on a case in a final status, writing
  the platform's archive state. Restore on an archived case.
- The Cases lenses exclude archived cases; an Archived lens shows them;
  `#MyWorkHome` tiles and the case search follow the same default.
- `case.archiveStatus` is mapped onto the state, not duplicated:
  archiving sets it and reads it back, and the ZGW delete guard in
  `ZrcController` is unchanged.
- An archived case renders read-only, with the platform's refusal message
  shown when somebody tries.

## Ownership

dossiq builds the action, the lens, the mapping and the read-only
rendering. It consumes openregister `object-archive-state`, to be
specified in openregister under that slug (row Q2.33): the archive marker,
the write refusal, the query and search exclusion and the restore. The
register's `dossiq_half`: "map archiefstatus onto it, hide archived cases
from the Cases lenses and offer Restore".

## ADRs

- Company ADR-022: the state is the platform's; dossiq keeps no second
  flag.
- Company ADR-097: the Archived lens adds no menu entry.
- Company ADR-105: the refusal on an archived case reaches the user as a
  message, not a silent failure.

## Capabilities

- Modified: `case-management`: a closed case is archived, leaves the
  working lenses and comes back.

## Impact

`src/manifest.json` `#CaseDetail`, `#Cases`, `#MyWorkHome`;
`lib/Settings/dossiq_register.json` (the mapping on `case.archiveStatus`);
`tests/vitest/caseActionsMenu.spec.js`, `caseListLenses.spec.js`; one e2e
spec. The ZGW controllers are untouched.

## Out of scope

- Archiving automatically on close. Whether a result type archives at once
  is a policy question and belongs with the retention rules.
- Anything about destruction. `archiveActionDate` and the destruction list
  are `retention-management`'s and do not move.
