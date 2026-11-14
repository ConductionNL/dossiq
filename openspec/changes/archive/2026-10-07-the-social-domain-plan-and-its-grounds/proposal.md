---
kind: code
depends_on: []
---

# Proposal: the-social-domain-plan-and-its-grounds

## The rows this closes

**5.18**, area Parties and contacts, rated `partial`: "Cross-domain view
showing only that a case exists, opened on a recorded ground."

Source field, verbatim: `dossiq#2314, published as 5.18`.

Corpus batch file, `procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`
in ConductionNL/market-intelligence, the table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **5.18** | 5.18 | Cross-domain view showing only that a case exists, opened on a recorded ground | partial | unread |  |
```

The ledger note, verbatim:

> sociaalDomeinAuditLog.authorisationGround is declared in the register and no code reads it. Row 5.4 asks for the opposite thing, a full 360 view, which purpose limitation does not allow between Wmo, Jeugdwet and Participatiewet.

**14.1**, area Case plan and services, rated `no`: "Case plan of
interventions, each with a goal, a provider and a target date."

Source field, verbatim: `dossiq#2314, published as 14.1`.

The table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **14.1** | 14.1 | Case plan of interventions, each with a goal, a provider and a target date | no | unread |  |
```

The ledger note, verbatim:

> The register declares gezinsplan.goals and deploymentTrajectories as plain string arrays, with no provider, no target date and no completion mark, and nothing reads them. The schema cannot express the row, so this is no and not partial.

## What the competitor evidence is

None for either row. Both are among the 98 rows promoted under decision D1,
whose batch file states: "Every competitor column is `unread`, and none of
them is `no`. ... `no` is a reading of a product somebody opened, and
filling these cells with it would fabricate thirty readings per row."

## Why

The social domain is where dossiq's register is most ambitious and its code
is thinnest, and these two rows are the clearest example of both.

**The plan is a list of strings.** `gezinsplan.goals` and
`deploymentTrajectories` are plain string arrays. A goal with no target
date, no provider and no way to mark it met is a note, not a plan. Nothing
reads them, so the thing a household's whole file is organised around, what
we agreed to do, who is doing it and by when, does not exist as data. The
row is `no` and not `partial` for the honest reason: the schema cannot
express it.

**The grounds are declared and unread.** `sociaalDomeinAuditLog.authorisationGround`
is in the register and no code reads it. Meanwhile the question a consulent
actually has is narrow: is this household already known to another domain,
so that I coordinate rather than duplicate. Purpose limitation does not
allow a 360 view between Wmo, Jeugdwet and Participatiewet, which is why row
5.4 asks for the wrong thing. It allows something much smaller: that a case
exists, in which domain, and who to call. Opened on a recorded ground, and
logged.

## What changes

- An `intervention` on the case plan: what it is, the goal it serves, the
  provider, the start and target dates, the state and the outcome. Several
  per plan, each with its own provider and its own dates.
- A goal that names what would count as met, so a plan can be evaluated
  rather than remembered.
- The existing `goals` and `deploymentTrajectories` string arrays are
  migrated into it, keeping the text, so nothing written down is lost.
- The plan is reviewable: a review date, who reviewed it, what changed. A
  plan that is never revisited is the failure this data is meant to catch.
- A cross-domain existence view: for a person, whether an open case exists
  in another domain, which domain, and the contact for it. Nothing else. No
  content, no status, no dates.
- Opening that view requires choosing a recorded ground, and the ground, the
  person, the moment and what was returned are written to
  `sociaalDomeinAuditLog`, which finally reads the field it declares.
- The person can be told what was looked up about them, because the log is
  now complete enough to answer.

## Ownership

dossiq builds the plan, the interventions and the existence view. What a
gezinsplan is under the Jeugdwet, and what one domain may know about
another, are case administration and dossiq's.

Consumed:
- openregister objects and relations (shipped) for the intervention and its
  provider, so the provider is a party in the platform's contact model
  rather than a string;
- openregister `row-field-level-security` (spec) for keeping the content of
  another domain's case unreadable while its existence is answerable, which
  dossiq already consumes through `sensitive-fields-declared`;
- openregister `cross-register-existence-query`, to be specified in
  openregister, for asking across registers whether a row exists without
  returning it. Until it lands dossiq answers the question over the registers
  it already reads, with the same projection and the same log;
- openregister audit trail (shipped) beside `sociaalDomeinAuditLog`, which
  keeps the ground because the ground is a dossiq fact.

## ADRs

- Company ADR-022: the query, the permission and the contact model are the
  platform's.
- Company ADR-031: the intervention states and the plan review are declared
  on the schema.
- Company ADR-047: the AVG request and erasure workflow are openregister's.
  This change writes the log that such a request reads and specifies none of
  that workflow.
- Company ADR-070: the plan and its interventions are OpenRegister objects,
  with no dossiq table.
- dossiq ADR-000, the entity catalogue, for where the plan sits beside the
  existing sociaal domein entities.

## Size

L. The plan is a new mechanism and the existence view is a new projection
with a gate on it.

## The existing spec this extends

`dossiq-sociaal-domein-jeugdwet`, which carries the gezinsplan, and
`dossiq-sociaal-domein-avg-consent`, which carries the classification, the
wijkteam guard and the audit log this change finally reads the ground from.

## Out of scope

- The 360 view of row 5.4. Purpose limitation does not allow it and this
  change deliberately does not approach it.
- The consent that gates a hand-off to another organisation, which is
  `custody-and-handover-of-a-case` REQ-CST-01. Looking up an existence
  inside the organisation is a different act with a different ground.
- The subject access request itself, which is openregister's under ADR-047.
- Contracting and paying a provider, which is not in these rows.
