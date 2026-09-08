---
kind: code
depends_on: []
---

# Proposal: case-type-authored-not-edited

Phase 3 of the round 2 competitor programme, DQ5. Rows 2.7 and 2.3 of
`concurrentie-analyse/procest/_round2/compare/placement.md`.

## Why

Two things about a case type are true at once, and they should not be.

**A status can be configured in five ways, and the page offers two of them.**
The `statusType` schema declares `description`, `role`, `colour`,
`hiddenInLists` and `checklist`, and every one of them reaches the running
product. A flow addresses a status by `role`, so a shipped flow works on a
type that calls its working phase Beoordeling. `colour` is read by four
rendering surfaces. `hiddenInLists` keeps closed cases off the Cases index.
Every `checklist` item becomes a task the moment a case enters the status, and
a required item holds the case there. `StatusesTab.vue` edits none of them. It
edits name, order, isFinal, and two properties called `notifyInitiator` and
`notificationText` that appear in no schema and are read by no code: measured,
they occur in exactly one file in the repository, which is the tab that writes
them. So the page offers two controls that configure nothing and withholds
five that configure a great deal, and everything interesting about a status is
set by hand-editing register JSON.

**A published case type is edited in place, under the cases running on it.**
`publish()` clears a draft flag; `CaseTypeCopyService::copy()` mints an
unrelated case type with a new identifier and a "Copy of" title. Neither makes
a version. The page's own banner says "changes will only apply to new cases",
and that is not true: `case.caseType` is the id of the row being edited, and an
edit reaches every case of that type immediately. There is no chain, so nothing
can say which version a running case started under, and nothing can say which
version a new case should get.

## What Changes

- `StatusesTab` edits every property the schema declares, through one
  `StatusTypeForm` used by both the add and the edit path. The two properties
  no schema declares are gone.
- `caseType` gains `version`, `previousVersion` and `supersededBy`, and a
  published case type gains a **New version** gesture beside Duplicate. The two
  are different: a duplicate is a second case type, a version is the same case
  type later on.
- Publishing a version writes `supersededBy` onto the version it replaces.
  That is the moment a version stops being offered for new cases, and it is
  the only one.
- Whether a case type may start a case is one function, `isCaseTypeUsable`, and
  the start-a-case widget asks it instead of carrying half of the rule.
- The admin page publishes through the same endpoint the in-app page uses. It
  used to validate in the browser and write the draft flag itself, which is a
  second implementation of the gesture that does not close the previous
  version.

## What running cases do

**Nothing.** A running case keeps pointing at the version it started under, and
that is the point of minting an object rather than editing one. `case.caseType`
is the id of one specific row; `case.status` is a `statusType` owned by that
row; the case's properties answer that row's `propertyDefinition`s. The
reference the case already holds is the pin, so no property is added to `case`.

There is deliberately no migration. Moving a running case to a newer version
would repoint `case.status` at a status id the new version does not contain and
recompute its deadline from a different `processingDeadline`. That needs a
status mapping per version pair, and it is a change of its own.

## Why three properties and not one

The plan asked for `previousVersion` alone. That is enough to record history
and not enough to run on, because four places have to answer "is this the
version a new case gets": the case types index, the start-a-case widget, the
create-a-case validation, and the case page notice. With only a backward link
each of them derives the answer by scanning every case type for the one nothing
points at, which is four implementations of one rule and a full-collection read
in a picker rendered on every page load. `supersededBy` makes it a field read.
`version` is what lets anything say "version 3" out loud.

`validFrom` and `validUntil` were considered as the chain, which is how ZGW
separates zaaktype versions. They are rejected as the mechanism: they are dates
an administrator sets for legal validity, they are legitimately blank, and a
version chain must not depend on a field whose empty state is normal.

## Out of scope

- **Refusing an in-place edit of a published version.** The chain makes the
  right gesture available and the banner honest; it does not yet stop the wrong
  one. Locking the form is a behaviour change on every case type in the fleet
  and belongs in its own change, with its own answer for the type that is
  published with zero cases on it.
- Migrating a running case to a newer version. See above.
- The `caseModel` authoring surface and the two-authoring-surfaces question,
  which `case-type-one-authoring-surface` already proposes.
