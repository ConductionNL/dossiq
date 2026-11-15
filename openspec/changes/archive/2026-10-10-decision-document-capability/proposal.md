# Proposal: decision document capability

## Why

Decision 182: procedures are configuration, code is generic. Capability C03 in hydra's
`procedures-are-configuration` design is "decision document: drafts, validates, signs, sends and
records a decision with its remedy clause". Today 21 procedure-named classes do it per procedure:
`BeschikkingService` and its family, `Subsidie\BeschikkingService`, `WOODecisionService`,
`Bezwaar\DecisionService` and more.

## What changes

- `Service/DecisionDocument/DecisionDocumentDefinition`: `decisionDocuments.<kind>` on the case type.
- `DecisionDocumentValidator`: required fields, remedy clause, rules as OpenRegister named conditions, failing closed.
- `DecisionDocumentLifecycle`: draft, approve (validity and delegated authority), sign (signature, number from #3624's series), send, archive.
- `DecisionAuthority`: the seam to the delegated-authority guard of lane R9 (C17); with nothing bound, approval of a kind that names an act is refused as indeterminate.
- The kind's frozen-record entry (C04, #3665).
- Case-type schema `register.d/39-decision-documents.json`; spec `decision-document`.

Nothing moves yet: the beschikking, Woo decision and bezwaar decision move onto this in later R5
PRs, after L10, L4, L6, L1 and #3572 have landed.

## Impact

New classes, unit-tested on a neutral fixture case type. No route, no screen, no migration.
