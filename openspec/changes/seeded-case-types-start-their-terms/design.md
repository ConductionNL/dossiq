# Design: seeded-case-types-start-their-terms

## Decision: bind by the key the resolver answers, not by the seed slug

A case carries its case type as a uuid. `CaseTypeSlugResolver` turns it into the key
a definition binds to: the case type's `identifier` first, then `@self.slug`. For the
English demo types and the event permits the two differ (`building-permit-type` versus
`building-permit`, `evenementenvergunning-v1` versus `evenementenvergunning-2024`), so a
definition keyed by the seed slug would bind to nothing. Every new row uses the
resolver's key, and the coverage test derives the key the same way.

## Decision: a service norm is `kind: planned`, and it must never arm the statutory term

The English demo types and the melding openbare ruimte have no beslistermijn: a
melding is not an aanvraag for a beschikking (Awb art. 1:3), and the demo types are
illustrations. Their duration is shipped as a planned clock. `activeFor()` now filters
on kind, because every caller of it (the statutory start, the rearm on a case-type
change, the status clock, the Woo term) means the statutory term.

## Decision: a planned definition fills in, it does not override

`bindForCase()` keeps the case type's own `plannedLeadTime` when it declares one, so no
existing case type moves. A planned definition is used only when the case type declares
none. Its counting mode is the definition's own: the shipped norms count calendar days
so they match the `processingDeadline` the case type shows.

## Decision: the event permit cites the model-APV and the Awb

An evenementenvergunning is granted under the gemeente's APV (model-APV art. 2:25). The
model-APV art. 1:2 lid 1 leaves the number of weeks to the gemeente and gives eight as
the example, which its toelichting ties to the Awb art. 4:13 lid 2 maximum; lid 2 allows
one adjournment of at most eight weeks. Eight weeks is therefore the term both under the
model and under the Awb fallback, and the case type declares P8W. The row says the
gemeente's own APV governs; a gemeente that set a different term changes the row.

## Decision: the cultuursubsidie uses the Awb fallback, the other two subsidy types do not

Awb title 4.2 sets no general beslistermijn for a subsidy application (checked against
the text of art. 4:21 to 4:80). Where the gemeente's ASV is silent, art. 4:13 lid 2 caps
the reasonable term at eight weeks. The cultuursubsidie declares exactly that (P8W), so
the fallback is a true statement of its term. The generic subsidieaanvraag (six weeks
plus four) and the innovatiefonds (thirteen weeks) declare numbers no generic rule gives,
so they stay open for Ruben rather than receive an invented basis.

## Decision: suspension is cited, not given pause durations

`pauseExtensionDuration` lists fixed pause lengths. Awb art. 4:15 suspends a term for as
long as the aanvulling takes, with no fixed length, and a klacht is not an aanvraag for a
beschikking, so 4:15 does not apply to it. The new rows ship no pause durations rather
than invent them.

## Decision (11 Oct): the other two subsidy types use the Awb fallback too

Ruben decided the open questions above. `subsidieaanvraag` and
`zaaktype-innovatiefonds-2026` each take eight weeks under Awb art. 4:13 lid 2, statutory,
with Awb art. 4:14 lid 3 as the notice of a later term, exactly as the cultuursubsidie
does. The case types change with them so the declared deadline and the definition agree:
`subsidieaanvraag` goes from six weeks plus a four week extension to P56D with no fixed
extension (a later term is a 4:14 lid 3 notice, the supervisor path, not a standard
extension), and the innovatiefonds goes from P13W to P8W. No open question is left.
