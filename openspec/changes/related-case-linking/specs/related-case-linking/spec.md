---
status: proposed
---

# Spec: related-case-linking

**Status:** proposed
**Scope:** procest
**Depends on:** case-management (existing `relatedCases` field), openregister (RBAC, per ADR-022)
**Related:** deelzaak-support (hierarchy — explicitly not duplicated here), zgw-api-mapping (relevanteAndereZaken delta in this change)

## Purpose

Typed peer relations between cases (RGBZ/ZRC `relevanteAndereZaken`): link a bezwaar to the
originating besluit-zaak, a WOO request to its bronzaken, a toezicht case to the vergunning it
follows. Relations are typed (`vervolg`, `onderwerp`, `bijdrage`), bidirectionally consistent,
guarded against self/duplicate/hierarchy overlap, and rendered on both case details with
RBAC-safe masking.

## ADDED Requirements

### Requirement: Case peer relations MUST be typed per RGBZ

The system SHALL store peer relations in the existing `case.relatedCases` array as `{caseId, aardRelatie, toelichting}` entries, where `aardRelatie` is one of `vervolg`, `onderwerp`, `bijdrage` per ZRC, and `toelichting` is an optional free-text clarification.

#### Scenario: Link a bezwaar to the original besluit-zaak

- **GIVEN** a bezwaar case and the case containing the contested besluit
- **WHEN** the handler links the besluit-zaak to the bezwaar with `aardRelatie = onderwerp` and a toelichting
- **THEN** the bezwaar's `relatedCases` MUST contain `{caseId: <besluit-zaak>, aardRelatie: onderwerp, toelichting}`

#### Scenario: Relation type is mandatory and constrained

- **WHEN** a relation is submitted without an `aardRelatie`, or with a value outside `vervolg`/`onderwerp`/`bijdrage`
- **THEN** the request MUST be rejected with a validation error

### Requirement: Peer relations MUST be bidirectionally consistent

Adding a relation SHALL make it visible from both cases; removing it from either side SHALL remove it from both; deleting a case SHALL remove its entries from all counterpart cases, leaving no dangling references.

#### Scenario: Relation visible from both sides

- **GIVEN** the bezwaar→besluit-zaak relation above
- **WHEN** a user opens the besluit-zaak's detail
- **THEN** its related-cases section MUST show the bezwaar with the inverse presentation of the same relation type

#### Scenario: Removal is two-sided

- **WHEN** the handler removes the relation from the besluit-zaak's side
- **THEN** the entry MUST disappear from the `relatedCases` of both cases

#### Scenario: Case deletion cleans up counterpart entries

- **GIVEN** a case linked as `bijdrage` to three other cases
- **WHEN** that case is deleted
- **THEN** the corresponding entries MUST be removed from all three counterpart cases' `relatedCases`

### Requirement: Relation creation MUST be guarded

The system SHALL reject self-relations, duplicate `{caseId, aardRelatie}` pairs, and peer relations that duplicate an existing direct hoofdzaak/deelzaak hierarchy link, and SHALL require the linking user to have read access to both cases under OpenRegister RBAC.

#### Scenario: Self-relation rejected

- **WHEN** a user attempts to relate a case to itself
- **THEN** the request MUST be rejected with a validation error

#### Scenario: Duplicate relation rejected

- **GIVEN** an existing `{caseId: X, aardRelatie: vervolg}` relation on a case
- **WHEN** the same pair is submitted again
- **THEN** the request MUST be rejected
- **AND** a relation to the same case X with a *different* `aardRelatie` MUST be accepted

#### Scenario: Hierarchy is not mirrored as a peer relation

- **GIVEN** case A is the parent (hoofdzaak) of case B per deelzaak-support
- **WHEN** a user attempts to peer-link A and B
- **THEN** the request MUST be rejected with an error referencing the existing hierarchy link

#### Scenario: Linking requires read access to both cases

- **GIVEN** a user who can read case A but not case B under OR RBAC
- **WHEN** they attempt to link A to B
- **THEN** the request MUST be denied

### Requirement: Related cases MUST be rendered on the case detail with RBAC-safe masking

The case detail SHALL show a "Gerelateerde zaken" section listing each relation with direction-aware type label, toelichting, and navigation; relations whose target the viewer cannot read SHALL render as a masked stub (case number only, no title, no navigation) without hiding the relation's existence.

#### Scenario: Section lists relations with navigation

- **GIVEN** a case with two readable related cases
- **WHEN** a handler opens the case detail
- **THEN** the Gerelateerde zaken section MUST list both with type label, case title, status, and a link navigating to each

#### Scenario: Add-relation flow

- **WHEN** the handler clicks "Zaak koppelen", searches for a case, selects the relation type, and confirms
- **THEN** the relation MUST be created and the section MUST update without a page reload

#### Scenario: Unreadable target is masked

- **GIVEN** a relation whose target case the viewer cannot read under OR RBAC
- **WHEN** the section renders
- **THEN** that entry MUST show only the case number and relation type, with no title and no navigation link
