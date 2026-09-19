---
kind: code
depends_on: []
---

# Proposal: splitting-a-case-and-its-incidents

## The rows this closes

**2.35**, area Case core, rated `partial`: "Split a case in two, dividing
documents and parties over both."

Source field, verbatim: `dossiq#2314, published as 2.29`.

Corpus batch file, `procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`
in ConductionNL/market-intelligence, the table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **2.35** | 2.29 | Split a case in two, dividing documents and parties over both | partial | unread | corpus 2.23 |
```

The ledger note, dossiq's own evidence, verbatim:

> CaseCopyService opens a second, separately numbered case and writes a typed vervolg relation back to the source, and DeelzaakService splits off a sub-case. Documents are linked whole rather than apportioned, and parties are not divided at all.

**2.45**, area Case core, rated `no`: "Incident on a case as a dated record
with its own owner."

Source field, verbatim: `dossiq#2314, published as 2.39`.

The table row verbatim, from the same corpus batch file:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **2.45** | 2.39 | Incident on a case as a dated record with its own owner | no | unread |  |
```

The ledger note, verbatim:

> A case holds one situation. Nothing models several dated incidents inside it, each with its own owner and its own transfer, which is how repeat reports on one address are handled elsewhere.

## What the competitor evidence is

None, for either row. Both are among the 98 promoted under decision D1, and
the batch file states it plainly: "Every competitor column is `unread`, and
none of them is `no`. ... `no` is a reading of a product somebody opened,
and filling these cells with it would fabricate thirty readings per row."

Row 2.35 carries a cross-reference to corpus row 2.23, the merge, which is
its inverse and is open in dossiq as `case-merge`. That cross-reference is a
neighbouring question, not a reading of a competitor on this row.

## Why

A case is treated as indivisible in both directions, and neither holds.

**Downwards.** Two complaints arrive on one form and turn out to be about
two different departments. `CaseCopyService` opens a second numbered case
and relates it back, and `DeelzaakService` hangs a sub-case underneath. Both
carry the file across whole. Nobody can say "these four documents and this
counter-party go to the new case, the rest stays here", so the split leaves
two cases each holding everything, and the handler cleans up by hand or does
not.

**Sideways.** One address generates a report in March, another in June and a
third in September. Today that is three cases or one case with three
paragraphs. An incident is a dated thing with its own reporter, its own
owner and its own outcome, sitting inside the case that holds the address
and the history. Without that record, the pattern that makes the case worth
keeping open is prose.

## What changes

- A split action on the case that divides rather than duplicates. The
  handler chooses which documents, which parties and which tasks go to the
  new case, and each chosen item moves, leaving a reference behind so the
  original file still reads as a whole.
- The split writes the typed relation both ways, reusing the relation
  `CaseCopyService` already writes rather than inventing a second one.
- What the split may divide is declared per case type, so a case type whose
  documents may not be separated says so once instead of relying on the
  handler.
- A `caseIncident` schema on the case: a date, a reporter, a description, an
  owner of its own, a state and an outcome. Several of them on one case.
- An incident carries its own hand-off, so the June report can sit with a
  different handler from the March one while the case stays with one owner.
- The case shows its incidents in date order, and a work list can count open
  incidents as well as open cases.

## Ownership

dossiq builds both. What may be divided out of a zaak, and what an incident
on a case is, are case administration and not platform behaviour.

Consumed: openregister object relations and the audit trail (shipped), so
the move of a document is attributable without dossiq writing a second
history; openregister `relation-types-with-inverses` (to be specified in
openregister, register row 2.26) for the inverse of the split relation, and
until it lands dossiq keeps writing both sides as `CaseRelationService` does
today.

## ADRs

- Company ADR-022: the relation, the move and the history are the
  platform's. dossiq declares what may move and asks.
- Company ADR-031: what a case type allows a split to divide is declared on
  the schema, not branched in a service.
- Company ADR-070: the incident is an OpenRegister object like everything
  else on the case, with no dossiq table.
- dossiq ADR-000, the entity catalogue, for where the incident sits beside
  the existing case entities.

## Size

M for the split, M for the incident. The change as a whole is M: no new
mechanism, two records and one action.

## The existing spec this extends

`case-management`, which carries REQ-CM-01 to REQ-CM-32 canonically and
REQ-CM-33 upwards from the open parity changes. This change adds
requirements and rewrites none.

## Out of scope

- Merging two cases into one. That is row 2.23 and `case-merge`, which this
  change deliberately does not touch.
- The deelzaak, which stays what it is: a sub-case under a parent, not a
  division of one.
- Dividing a decision or a term. A split before a decision is the only case
  this change supports, and a case type may forbid it later.
