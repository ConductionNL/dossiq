---
kind: code
depends_on: []
---

# Proposal: split-picker-asks-the-policy

A one-idea follow-up to `splitting-a-case-and-its-incidents` (#2945) and the
surface that reached it (#2957).

## Why

The rule is enforced and the handler cannot read it. `CaseSplitPolicy` decides
which parts a case type allows a split to divide, and the write path consults
it, but the picker lists every document and party the case holds regardless.
So on a case type that forbids dividing documents a handler ticks a document,
types a title, confirms, and only then reads that documents may not be
divided here. The work is thrown away and the rule was knowable all along.

Beside it sits a second, quieter defect. The dialog's one empty line reads
"This case type does not allow a split to divide anything", and it fires when
the case simply HAS no documents and no parties. A handler with an empty case
is told their case type is the problem, and goes to ask an administrator to
change a declaration that was never in the way.

## What changes

- `CaseSplitExecutor::divisibleParts()` answers `CaseSplitPolicy::allowedFor()`
  for one case, and `GET /api/case/{caseId}/split` publishes it.
- The dialog asks that before it draws, renders a section only for an allowed
  part, and reads only the parts it may offer.
- The two empty states are told apart: a case type that allows nothing, and a
  case that holds nothing of what it allows.

## Ownership

dossiq. The rule, the surface and the sentence are all case administration.

## Capabilities

- Modified: `case-management`: the picker says what may be divided before the
  handler chooses.

## Impact

`lib/Service/Cases/CaseSplitExecutor.php`;
`lib/Controller/CaseSplitController.php`; `appinfo/routes.php`;
`src/dialogs/CaseSplitDialog.vue`; unit tests; one vitest spec.
