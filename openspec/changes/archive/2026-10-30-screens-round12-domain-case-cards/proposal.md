# Domain cards on the one case page

## Why

Boards DqBezwaar, DqSubsidie, DqSociaalDomein and DqAanbesteding are the case
page of a case of one kind: an objection, a grant, a youth care report, a
tender. Procedures are case types and configuration, so these are not pages of
their own: the generic case page shows extra side cards when the case type's
category asks for them.

## What changes

The simple profile's `CaseDetail` side column gains six cards, each gated by
`visibleWhen` on `@context.caseType.category` and each listing records that
name this case:

- objection ("Bezwaar en beroep"): Hearing (hearingSession), Advice of the
  committee (advisoryReport), Appeal (beroep)
- grants ("Subsidies"): Grant application (subsidieAanvraag)
- social domain ("Sociaal domein", or a case naming a family plan): Consent
  (toestemming)
- procurement ("Inkoop en leveranciers"): Bids (supplierTender)

The case tab strip gains gated tabs over the same kind of records: Hearing,
Advice and Decision on the objection; Indication and Case meeting in the
social domain; Bids on a tender, which now carry their price score, quality
score, total and rank (`supplierTender` 1.2.0, fragment 97).

No card carries text the register does not back. The need-to-know banner reads
the case type's special data categories; the mandate line is the decisions
card's notice, answered by decidiq's mandate register.

## Depends on

nextcloud-vue #1738 (side column `visibleWhen`). Without it every card would
show on every case, so this ships with the dependency bump that carries it.
