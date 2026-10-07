---
kind: code
depends_on: []
---

# Proposal: markers-and-assessments-on-the-case

## The rows this closes

**2.36**, area Case core, rated `no`: "Flag a case as needing attention,
cleared only with a written reason."

Source field, verbatim: `dossiq#2314, published as 2.30`.

Corpus batch file, `procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`
in ConductionNL/market-intelligence, the table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **2.36** | 2.30 | Flag a case as needing attention, cleared only with a written reason | no | unread |  |
```

The ledger note, verbatim:

> Row 2.8 tags are free labels anyone can remove silently. Nothing demands a written reason on raising or clearing, and nothing attributes either act.

**2.40**, area Case core, rated `no`: "Assessed risk level on the case,
separately permissioned."

Source field, verbatim: `dossiq#2314, published as 2.34`.

The table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **2.40** | 2.34 | Assessed risk level on the case, separately permissioned | no | unread | discovery D-jsm-17 |
```

The ledger note, verbatim:

> lhsRecommendation carries a severity read by LhsLookupService, but it is scoped to VTH. It is not on the case schema, it is not separately permissioned, and it drives neither priority nor filtering, so all three qualifiers of the row fail.

**2.44**, area Case core, rated `no`: "Attention marker pointing at one tab
of the case, cleared by handling it."

Source field, verbatim: `dossiq#2314, published as 2.38`.

The table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **2.44** | 2.38 | Attention marker pointing at one tab of the case, cleared by handling it | no | unread |  |
```

The ledger note, verbatim:

> Row 2.30 is a marker a person raises on the whole case. This is one the system raises against a specific tab and drops when the work behind that tab is done.

## What the competitor evidence is

None for any of the three. All three are among the 98 rows promoted under
decision D1, whose batch file states: "Every competitor column is `unread`,
and none of them is `no`. ... `no` is a reading of a product somebody
opened, and filling these cells with it would fabricate thirty readings per
row."

Row 2.40 cross-references round 4 discovery candidate D-jsm-17. A
cross-reference names a neighbouring question; it is not a reading of a
product on this row.

## Why

Three things sit on a case and say "look here". dossiq has none of them,
and the nearest thing it has is a free label.

**A flag a person raises.** Tags exist, and anyone can add or remove one
without saying why and without leaving a name. A flag that says "this case
needs attention" is only worth anything if clearing it costs something: a
sentence, and an attribution. Otherwise the first person who finds it
inconvenient makes it go away.

**An assessment the organisation makes.** `lhsRecommendation` carries a
severity, and `LhsLookupService` reads it, and it is VTH's. It is not on the
case, so no other domain has one. It is readable by anyone who may read the
case, so a risk assessment about a household is as open as the address. And
nothing reads it for priority or filtering, so it informs no decision the
system makes.

**A marker the system raises.** A document that failed a virus scan, an
unanswered advice request, a party whose address bounced. Each belongs to
one tab of the case, and each stops being true when the work behind that tab
is done. dossiq raises none of them, so a handler opening a case reads every
tab to find the one that needs them.

`unread-state-on-the-case` is the nearest open change and it is a different
fact. Its REQ-URS-02 marks a tab that holds something this user has not
read, and its own scenario clears the badge when the tab is opened. An
attention marker is not per user and opening the tab does not clear it: it
clears when the thing behind it is handled. The two can be true at once and
they mean different things.

## What changes

- An attention flag on the case: raised with a written reason and a name,
  cleared with a written reason and a name, both kept. The history of
  raisings and clearings is readable, so a case that keeps being flagged
  shows it.
- The flag is a countable, filterable fact on the work list, so "the flagged
  ones" is a view rather than a search for a tag.
- A `riskAssessment` on the case schema, available to every domain rather
  than VTH alone: a level, the ground it rests on, who assessed it and when,
  and when it should be looked at again.
- The assessment is behind its own permission, declared in the same
  vocabulary `sensitive-fields-declared` uses, so reading a case does not
  mean reading its risk level.
- The assessment feeds the impact input of `case-priority-impact-urgency`
  rather than becoming a fifth priority word, and it filters the work list
  for the people allowed to see it.
- Attention markers raised by the system against a named tab, each declaring
  what raises it and what clears it. Clearing is doing the work, not opening
  the tab.

## Ownership

dossiq builds the flag, the assessment and the markers. What deserves
attention on a zaak, and what a risk assessment means beside a decision, are
case administration.

Consumed:
- openregister `row-field-level-security` (spec) for the extra permission on
  the assessment, exactly as dossiq's `sensitive-fields-declared` consumes it
  for the BSN and the special categories;
- openregister audit trail (shipped) for the attribution of each raising and
  clearing, so dossiq keeps the reasons and not a second history;
- openregister `lifecycle-declarative-conditions`, to be specified in
  openregister, for the conditions that raise and clear a system marker.
  Until it lands, dossiq declares the conditions on the schema and evaluates
  them where the event already reaches it.

## ADRs

- Company ADR-022: the permission and the history are the platform's.
- Company ADR-031: what raises a marker and what clears it is declared on
  the schema, not written as a service per marker. A marker per service is
  how the case ends up with six of them that disagree.
- Company ADR-038: the requirement ids below carry the canonical form.
- Company ADR-078: a marker raised from an object event is post-event work
  and is placed accordingly, so raising one never slows the write that
  caused it.

## Size

M. Two records, one declaration and a permission that already has a
vocabulary.

## The existing spec this extends

`case-management`. It does not touch `unread-state-on-the-case`'s
REQ-URS-01 to REQ-URS-04, which describe the per-user read state beside
this.

## Out of scope

- Tags. They stay what they are, free labels, and this change does not turn
  them into something governed.
- The per-user unread state, which is `unread-state-on-the-case`.
- The priority derivation itself, which is `case-priority-impact-urgency`
  REQ-PRI-02. This change feeds it an input and does not restate it.
- The VTH severity in `lhsRecommendation`, which keeps its own meaning
  inside the LHS tables. The case-level assessment sits beside it rather
  than replacing it.
